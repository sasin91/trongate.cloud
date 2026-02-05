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

        $label = trim($_GET['label'] ?? '');
        $project_id = filter_var($_GET['project_id'] ?? null, FILTER_VALIDATE_INT);

        $project = $this->model->find_project($project_id);

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
            'server_types' => $server_types,
            'project' => $project,
            'projects' => $project === null ? $this->model->get_team_project($team_id) : null,
            'label' => $label
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function server_types_options(): void {
        $this->_make_sure_allowed();
        
        $location = htmlspecialchars(trim($_GET['location'] ?? 'fsn1'));
        
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
        $this->validation->set_rules('project_id', 'Project', 'integer|callback_validate_project_id');

        if ($this->validation->run() == false) {
            redirect('servers/create');
            exit;
        }

        // Get form data
        $label = post('label', true);
        $region = post('region');
        $server_type = post('server_type');
        $provider = post('provider', true);
        $project_id = post('project_id', true);

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

        $server_id = $this->model->insert($data);

        redirect("servers/show/$server_id?project_id=$project_id");
    }

    function show(): void {
        $this->_make_sure_allowed();
        
        // Get team ID
        $team_id = $this->model->get_customer_team_id();
        
        if ($team_id === false) {
            redirect('customer-onboarding/team');
        }

        $server_id = segment(3, 'int');
        $project_id = filter_var($_GET['project_id'] ?? null, FILTER_VALIDATE_INT);

        $server = $this->model->db->query_bind(
            'SELECT * FROM server WHERE id = :server_id AND team_id = :team_id LIMIT 1',
            ['server_id' => $server_id, 'team_id' => $team_id],
            'object'
        );

        if (empty($server)) {
            redirect('customer');
        }

        $data = [
            'view_module' => 'servers',
            'view_file' => 'show',
            'server' => $server,
            'project_id' => $project_id
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function deploy(): void {
        $this->_make_sure_allowed();

        $team_id = $this->model->_get_current_team_id();

        if ($team_id === false) {
            redirect('customer-onboarding/team');
        }

        $this->validation->set_rules('server_id', 'Server', 'required|integer|callback_validate_server_id');
        $this->validation->set_rules('project_id', 'Project', 'required|integer|callback_validate_project_id');

        if ($this->validation->run() == false) {
            redirect('customer');
            exit;
        }

        $server_id = filter_var(post('server_id'), FILTER_VALIDATE_INT);
        $project_id = filter_var(post('project_id'), FILTER_VALIDATE_INT);
        
        // Get project for this team to check for repository
        $project = $this->model->find_project($project_id);

        // Create deployment record
        $deployment_id = $this->model->create_deployment([
            'team_id' => $team_id,
            'customer_id' => $_SESSION['customer_id'],
            'project_id' => $project->id,
            'server_id' => $server_id,
            'status' => 'pending',
            'source_type' => 'git',
            'source_url' => $project->source_url,
            'metadata' => json_encode([
                'source_provider' => $project->source_provider,
                'repository' => $project->repository_full_name,
                'branch' => $project->repository_branch ?? 'main'
            ])
        ]);

        // Queue deployment task
        $this->model->queue_task($server_id, 'deploy_repository', [
            'deployment_id' => $deployment_id,
            'source_provider' => $project->source_provider,
            'repository' => $project->repository_full_name,
            'branch' => $project->repository_branch ?? 'main',
            'project_id' => $project->id,
            'project_type' => $project->type,
            'server_ip' => $server['ip'] ?? null
        ], $deployment_id);

        // Redirect to deployment status page
        redirect("deployments/show/{$deployment_id}");
    }

    function _validate_provider(string $provider): bool|string {
        $this->module('services');
        
        if ($this->services->exists($provider) === false) {
            return 'Invalid provider.';
        }

        return true;
    }

    function _validate_project_id($project_id): bool|string {
        if (empty($project_id)) {
            return "Please select a project.";
        }

        $this->module('servers');
        $project = $this->model->find_project($project_id);

        if ($project === null) {
            return 'Selected project does not exist.';
        }

        $team_id = $this->model->get_customer_team_id();

        if ($project->team_id != $team_id) {
            return 'Selected project does not belong to your team.';
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
