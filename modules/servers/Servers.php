<?php

/**
 * @property-read Servers_model $model
 * @property-read Secrets $secrets
 * @property-read Services $services
 * @property-read Templates $templates
 * @property-read Trongate_tokens $trongate_tokens
 */
class Servers extends Trongate {
    
    function create(): void {
        $this->_make_sure_allowed();
        
        // Get team ID
        $team_id = $this->model->get_customer_team_id();
        
        if ($team_id === false) {
            redirect('customer-onboarding/team');
        }

        // Get Hetzner API Key
        $this->module('secrets');
        $secret = $this->secrets->get('team', $team_id, 'hetzner');
        $token = $secret['token'] ?? null;

        if (empty($token)) {
            redirect('customer-onboarding/provider');
        }

        // Init Hetzner
        $this->module('services');
        $hetzner = $this->services->hetzner($token);
        $regions = $hetzner->list_regions();
        $server_types = $hetzner->list_server_types();

        $data = [
            'view_module' => 'servers',
            'view_file' => 'create',
            'regions' => $regions,
            'server_types' => $server_types
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function server_types_options(): void {
        $this->_make_sure_allowed();
        
        $location = $_GET['location'] ?? 'fsn1';
        
        // Get team ID
        $team_id = $this->model->get_customer_team_id();
        
        if ($team_id === false) {
            http_response_code(403);
            return;
        }

        // Get Hetzner API Key
        $this->module('secrets');
        $secret = $this->secrets->get('team', $team_id, 'hetzner');
        $token = $secret['token'] ?? null;

        if (empty($token)) {
            http_response_code(403);
            return;
        }

        // Get server types for this location
        $this->module('services');
        $hetzner = $this->services->hetzner($token);
        $server_types = $hetzner->list_server_types($location);

        // Output just the options using shared partial
        header('Content-Type: text/html');
        $this->view('_server_type_options', ['server_types' => $server_types]);
    }

    function submit(): void {
        $this->_make_sure_allowed();

        // Get team ID
        $team_id = $this->model->get_customer_team_id();

        if ($team_id === false) {
            redirect('customer-onboarding/team');
        }

        $this->validation->set_rules('label', 'Server Label', 'required|max_length[255]');
        $this->validation->set_rules('provider', 'Cloud Provider', 'required|callback_validate_provider'); // For now we only support Hetzner, but this allows for future expansion
        $this->validation->set_rules('region', 'Region', 'required|min_length[3]|max_length[5]'); // Hetzner region codes are short, e.g. "fsn1", "nbg1", "hel1" so 4 + 1 characters should be enough
        $this->validation->set_rules('server_type', 'Server Type', 'required|min_length[4]|max_length[6]'); // Hetzner server type names can be long, e.g. "cpx31", "ccx51", "cx41"
        
        if ($this->validation->run() == false) {
            redirect('servers/create');
            exit;
        }

        // Get form data
        $label = post('label', true);
        $region = post('region');
        $server_type = post('server_type');
        $provider = post('provider', true);

        // Validate required fields
        if (empty($label) || empty($region) || empty($server_type)) {
            redirect('servers/create');
        }

        // Get provider API Key
        $this->module('secrets');
        $secret = $this->secrets->get('team', $team_id, $provider);

        if (empty($secret)) {
            redirect('customer-onboarding/provider');
        }

        // Create server via Provider
        $this->module('services');
        /** @var Hetzner $provider_service */
        $provider_service = $this->services->$provider($secret['token']);

        // Get customer SSH key from team secrets
        $ssh_secret = $this->secrets->get('team', $team_id, 'ssh_key');

        $customer_key_id = sprintf('%s_id', $provider);
        $ssh_key_ids = [
            $ssh_secret[$customer_key_id]
        ];

        // Load base cloud-init script and configure CA
        $cloud_init_path = __DIR__ . '/cloud-init/base-server.yml';
        $user_data = file_get_contents($cloud_init_path);
        $user_data = str_replace('{{CA_PUBLIC_KEY}}', PLATFORM_SSH_CA_PUBLIC_KEY, $user_data);

        // Create server with proper parameters
        $server = $provider_service->create_server(
            name: $label,           
            type: $server_type,     
            region: $region,             
            ssh_keys: $ssh_key_ids, 
            labels: ['managed_by' => 'trongate'],               
            user_data: $user_data        
        );

        // Store server in database
        $data = [
            'team_id' => $team_id,
            'provider' => $provider,
            'provider_id' => $server['provider_id'],
            'label' => $label,
            'region' => $region,
            'server_type' => $server_type,
            'ipv4' => $server['ip'] ?? null,
            'ipv6' => null,
            'status' => $server['status']
        ];

        $this->model->insert($data);

        // Redirect to server details or dashboard
        redirect('customer');
    }

    function _validate_provider(string $provider): bool|string {
        $this->module('services');
        
        if ($this->services->exists($provider) === false) {
            return 'Invalid provider.';
        }

        return true;
    }

    function _make_sure_allowed(): void {
        $this->module('trongate_tokens');
        $token = $this->trongate_tokens->attempt_get_valid_token(2);

        if ($token === false) {
            redirect('customer/login');
        }
    }
}
