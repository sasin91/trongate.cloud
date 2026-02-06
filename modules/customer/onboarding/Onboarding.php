<?php

/**
 * @property-read Trongate_security $trongate_security
 * @property-read Onboarding_model $model
 * @property-read Validation $validation
 * @property-read Services $services
 * @property-read Secrets $secrets
 * @property-read Encryption $encryption
 */
class Onboarding extends Trongate {

    function __construct(string $module_name = '') {
        parent::__construct($module_name);
        $this->_make_sure_allowed();
    }

    function index(): void {
        $customer = $this->model->_get_current_customer();

        if ($customer === false) {
            redirect('customer/login');
            exit;
        }

        $is_onboarded = $this->model->is_onboarded($customer->id);

        if ($is_onboarded === true) {
            redirect('customer');
            exit;
        }

        $team_id = $this->model->_get_current_team_id($customer->id);

        if ($team_id === false) {
            redirect('customer-onboarding/team');
            exit;
        }

        $this->module('secrets');

        // Check for hosting provider (Hetzner) - required
        if (!$this->secrets->exists('team', $team_id, 'hetzner')) {
            redirect('customer-onboarding/provider');
            exit;
        }

        // Check for SSH key - required for server access
        if (!$this->secrets->exists('team', $team_id, 'ssh_key')) {
            redirect('customer-onboarding/ssh_key');
            exit;
        }

        $team = $this->model->db->get_where($team_id, 'team');

        if ($team->services) {
            $team_services = json_decode($team->services, true);
            $source_service = array_find($team_services, fn(array $svc) => $svc['type'] === 'source');

            if (empty($source_service)) {
                redirect('customer-onboarding/source');
                exit;
            }
        }

        redirect('customer-onboarding/project');
        exit;
    }

    function team(): void {
        $this->view('team');
    }

    function submit_team(): void {
        $customer = $this->model->_get_current_customer();

        if (!$customer) {
            redirect('customer/login');
        }

        $this->validation->set_rules('name', 'Team name', 'required|min_length[3]|max_length[50]');
        $validated = $this->validation->run();

        if ($validated === false) {
            $this->team();
            return;
        }

        $name = post('name', true);

        $team_id = $this->model->create_team($customer->id, $name);
        $this->model->add_team_member($team_id, $customer->id, 'owner');
        $this->model->set_current_team($team_id);

        redirect('customer-onboarding/provider');
    }

    // --- Hosting Provider (Hetzner) ---

    function provider(): void {
        $this->view('provider');
    }

    function submit_provider(): void {
        $customer = $this->model->_get_current_customer();
        if (!$customer) {
            redirect('customer/login');
            exit;
        }

        $team_id = $this->model->_get_current_team_id($customer->id);

        if ($team_id === false) {
            redirect('customer-onboarding/team');
            exit;
        }

        $this->validation->set_rules('provider', 'Cloud Provider', 'required|callback_validate_provider');
        $this->validation->set_rules('token', 'API Token', 'required|min_length[10]|callback_validate_hetzner_token');

        if ($this->validation->run() === false) {
            redirect('customer-onboarding/provider');
            exit;
        }

        $provider = post('provider', true);
        $token = post('token', true);

        $this->module('encryption');
        $this->module('secrets');

        $encryption_key = $this->encryption->generate_key();
        $this->secrets->save('team', $team_id, $provider, $encryption_key, ['token' => $token]);

        redirect('customer-onboarding/ssh_key');
    }

    // --- SSH Key ---

    function ssh_key(): void {
        $this->view('ssh_key');
    }

    function submit_ssh_key(): void {
        $customer = $this->model->_get_current_customer();
        if (!$customer) {
            redirect('customer/login');
            exit;
        }

        $team_id = $this->model->_get_current_team_id();
        if ($team_id === false) {
            redirect('customer-onboarding/team');
            exit;
        }

        $this->validation->set_rules('provider', 'Cloud Provider', 'required|callback_validate_provider');
        $this->validation->set_rules('public_key', 'SSH Public Key', 'required|callback_validate_ssh_key');
        $this->validation->set_rules('label', 'Key Label', 'max_length[50]');

        if ($this->validation->run() === false) {
            $this->ssh_key();
            exit;
        }

        $provider = post('provider', true);
        $public_key = post('public_key', true);
        $label = post('label', true) ?: sprintf('team_%d', $team_id);

        $this->module('encryption');
        $this->module('secrets');

        $provider_secret = $this->secrets->get('team', $team_id, $provider);

        $this->module('services');
        $provider_service = $this->services->$provider($provider_secret['token']);
        
        $provider_ssh_id = '';
        try {
            $provider_ssh_id = $provider_service->upload_ssh_key($label, $public_key);
        } catch (Client_Error $e) {
            switch (true) {
                case $e->getCode() === 409:
                    foreach ($provider_service->list_ssh_keys() as $key) {
                        // Extract the base64 key data (second part) for comparison to handle whitespace differences
                        $stored_key_parts = preg_split('/\s+/', trim($key['public_key']));
                        $input_key_parts = preg_split('/\s+/', trim($public_key));
                        if (isset($stored_key_parts[1], $input_key_parts[1]) && $stored_key_parts[1] === $input_key_parts[1]) {
                            $provider_ssh_id = $key['id'];
                            $label = $key['name'];
                            break 2; // break 2 levels (switch and foreach)
                        }
                    }
                break;

                default:
                    throw $e;
                break;
            }

            if (empty($provider_ssh_id)) {
                throw new Exception('SSH key not found');
            }
        }

        $customer_key_id = sprintf('%s_id', $provider);

        $encryption_key = $this->encryption->generate_key();
        $this->secrets->save('team', $team_id, 'ssh_key', $encryption_key, [
            'public_key' => $public_key,
            'label' => $label,
            $customer_key_id => $provider_ssh_id
        ]);

        redirect('customer-onboarding/source');
    }

    // --- Source Control (GitHub) ---

    function source(): void {
        $this->view('source');
    }

    function submit_source(): void {
        $customer = $this->model->_get_current_customer();
        if (!$customer) {
            redirect('customer/login');
            exit;
        }

        $team_id = $this->model->_get_current_team_id();

        if ($team_id === false) {
            redirect('customer-onboarding/team');
            exit;
        }

        $this->validation->set_rules('provider', 'Source Control Provider', 'required|callback_validate_provider');
        $this->validation->set_rules('token', 'Personal Access Token', 'required|min_length[10]|callback_validate_github_token');

        if ($this->validation->run() === false) {
            $this->source();
            exit;
        }

        $provider = post('provider', true);
        $token = post('token', true);

        $this->module('encryption');
        $this->module('secrets');

        $encryption_key = $this->encryption->generate_key();
        $this->secrets->save('team', $team_id, $provider, $encryption_key, ['token' => $token]);

        $this->model->add_team_service($team_id, 'source', $provider);

        redirect('customer-onboarding/project');
    }

    // --- Project ---

    function project(): void {
        $team_id = $this->model->_get_current_team_id();
        if ($team_id === false) {
            redirect('customer-onboarding/team');
            exit;
        }

        $team = $this->model->db->get_where($team_id, 'team');
        $team_services = json_decode($team->services ?? '[]', true);
        $source_service = array_find($team_services, fn(array $svc) => $svc['type'] === 'source');

        $this->module('secrets');
        $source_secret = $this->secrets->get('team', $team_id, $source_service['id']);

        if (!$source_secret) {
            redirect('customer-onboarding/source');
            exit;
        }

        $this->module('services');
        $service = $this->services->{$source_service['id']}($source_secret['token']);
        $repositories = $service->list_repositories();

        $this->view('project', [
            'provider' => $source_service['id'],
            'repositories' => $repositories
        ]);
    }

    function submit_project(): void {
        $customer = $this->model->_get_current_customer();
        if (!$customer) {
            redirect('customer/login');
        }

        $team_id = $this->model->_get_current_team_id();

        if ($team_id === false) {
            redirect('customer-onboarding/team');
        }

        $this->validation->set_rules('provider', 'Source Control Provider', 'required|callback_validate_provider');
        $this->validation->set_rules('repository', 'Repository', 'required');
        $this->validation->set_rules('repository_branch', 'Repository Branch', 'required');
        $this->validation->set_rules('label', 'Project Name', 'required|min_length[3]|max_length[50]');
        $this->validation->set_rules('type', 'Project Type', 'required');

        if ($this->validation->run() === false) {
            $this->project();
            return;
        }

        $provider = post('provider', true);
        $repository = post('repository', true);
        $repository_branch = post('repository_branch', true);
        $label = post('label', true);
        $type = post('type', true);

        $this->module('secrets');
        $source_secret = $this->secrets->get('team', $team_id, $provider);
        /** @var Github $source_service */
        $source_service = $this->services->$provider($source_secret['token']);
        $source_url = $source_service->url($repository);

        $this->model->db->transaction(function () use ($team_id, $label, $type, $customer, $source_url, $provider, $repository_branch) {
            $project_id = $this->model->create_project(
                team_id: $team_id, 
                label: $label, 
                type: $type ?? 'trongate_v1', 
                source_url: $source_url,
                source_provider: $provider,
                source_branch: $repository_branch
            );
            $updated = $this->model->mark_onboarded($customer->id);

            return $project_id !== false && $updated;
        });

        redirect('customer');
    }

    function repository_branch_options(): void {
        $repository = trim($_GET['repository'] ?? '');
        $provider = trim($_GET['provider'] ?? '');
        
        $team_id = $this->model->_get_current_team_id();

        $this->module('secrets');
        $source_secret = $this->secrets->get('team', $team_id, $provider);

        $this->module('services');
        /** @var Github $source_service */
        $source_service = $this->services->$provider($source_secret['token']);
        $branches = $source_service->list_branches($repository);

        // Output just the options using shared partial
        header('Content-Type: text/html');
        $this->view('_repository_branch_options', ['branches' => $branches]);
    }

    // --- Validation Callbacks ---

    function _validate_hetzner_token(string $token): bool|string {
        if (empty($token)) {
            return 'API Token is required.';
        }

        $this->module('services');
        $client = $this->services->hetzner($token);

        if (!$client->validate_credentials()) {
            return 'Invalid API token. Please check your Hetzner Cloud API key.';
        }

        return true;
    }

    function _validate_github_token(string $token): bool|string {
        if (empty($token)) {
            return 'Personal Access Token is required.';
        }

        $this->module('services');
        $client = $this->services->github($token);

        if (!$client->validate_credentials()) {
            return 'Invalid token. Please check your GitHub Personal Access Token.';
        }

        return true;
    }

    function _validate_ssh_key(string $public_key): bool|string {
        if (empty($public_key)) {
            return 'SSH Public Key is required.';
        }

        // Trim whitespace
        $public_key = trim($public_key);

        // Valid SSH key types
        $valid_types = ['ssh-rsa', 'ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521'];

        // Check if the key starts with a valid type
        $starts_with_valid_type = false;
        foreach ($valid_types as $type) {
            if (str_starts_with($public_key, $type . ' ')) {
                $starts_with_valid_type = true;
                break;
            }
        }

        if (!$starts_with_valid_type) {
            return 'Invalid SSH key format. Key must start with ssh-rsa, ssh-ed25519, or ecdsa-sha2-*.';
        }

        // Basic structure check: should have at least 2 parts (type and key data)
        $parts = preg_split('/\s+/', $public_key);
        if (count($parts) < 2) {
            return 'Invalid SSH key structure. Expected format: [type] [key-data] [optional-comment]';
        }

        // Validate the key data part is base64
        $key_data = $parts[1];
        if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $key_data)) {
            return 'Invalid SSH key data. Key must be base64 encoded.';
        }

        return true;
    }

    function _validate_provider(string $provider): bool|string {
        $this->module('services');
        
        if ($this->services->exists($provider) === false) {
            return 'Invalid provider.';
        }

        return true;
    }

    /**
     * Ensure the current user has appropriate access permissions.
     */
    function _make_sure_allowed(): ?string {
        $token = $this->trongate_tokens->attempt_get_valid_token(2);

        if ($token === false) {
            redirect('customer/login');
        }

        $customer = $this->model->_get_current_customer();

        if ($customer === false) {
            redirect('customer/login');
        }

        if ($customer->active && $customer->onboarded_at !== null) {
            redirect('customer');
        }

        return $token;
    }
}
