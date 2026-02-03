<?php

/**
 * @property-read Trongate_security $trongate_security
 * @property-read Onboarding_model $model
 * @property-read Validation $validation
 * @property-read Services $services
 * @property-read Secrets $secrets
 * @property-read Encryption $encryption
 */
class Onboarding extends Trongate
{
    public function __construct(string $module_name = '')
    {
        parent::__construct($module_name);
        $this->_make_sure_allowed();
    }

    public function index(): void
    {
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

        $teams = $this->model->get_owned_teams($customer->id);

        if (empty($teams)) {
            redirect('customer-onboarding/team');
            exit;
        }

        $team = $teams[0];
        $this->module('secrets');

        // Check for hosting provider (Hetzner) - required
        if (!$this->secrets->exists('team', $team->id, 'hetzner')) {
            redirect('customer-onboarding/provider');
            exit;
        }

        // Source control (GitHub) is optional - user can skip and use zip upload
        // So we go straight to project step
        redirect('customer-onboarding/project');
        exit;
    }

    public function team(): void
    {
        $this->view('team');
    }

    public function submit_team(): void
    {
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

        if ($team_id) {
            redirect('customer-onboarding/provider');
        } else {
            redirect('customer-onboarding/team');
        }
    }

    // --- Hosting Provider (Hetzner) ---

    public function provider(): void
    {
        $this->view('provider');
    }

    public function submit_provider(): void
    {
        $customer = $this->model->_get_current_customer();
        if (!$customer) {
            redirect('customer/login');
            exit;
        }

        $teams = $this->model->get_owned_teams($customer->id);
        if (empty($teams)) {
            redirect('customer-onboarding/team');
            exit;
        }
        $team_id = $teams[0]->id;

        $this->validation->set_rules('token', 'API Token', 'required|min_length[10]|callback_validate_hetzner_token');

        if ($this->validation->run() === false) {
            $this->provider();
            exit;
        }

        $token = post('token', true);

        $this->module('encryption');
        $this->module('secrets');

        $encryption_key = $this->encryption->generate_key();
        $this->secrets->save('team', $team_id, 'hetzner', $encryption_key, ['token' => $token]);

        redirect('customer-onboarding/source');
    }

    // --- Source Control (GitHub) ---

    public function source(): void
    {
        $this->view('source');
    }

    public function submit_source(): void
    {
        $customer = $this->model->_get_current_customer();
        if (!$customer) {
            redirect('customer/login');
            exit;
        }

        $teams = $this->model->get_owned_teams($customer->id);
        if (empty($teams)) {
            redirect('customer-onboarding/team');
            exit;
        }
        $team_id = $teams[0]->id;

        $this->validation->set_rules('token', 'Personal Access Token', 'required|min_length[10]|callback_validate_github_token');

        if ($this->validation->run() === false) {
            $this->source();
            exit;
        }

        $token = post('token', true);

        $this->module('encryption');
        $this->module('secrets');

        $encryption_key = $this->encryption->generate_key();
        $this->secrets->save('team', $team_id, 'github', $encryption_key, ['token' => $token]);

        redirect('customer-onboarding/project');
    }

    public function skip_source(): void
    {
        // User chose to skip GitHub - they'll upload zip files instead
        // We don't store anything, just proceed to project
        redirect('customer-onboarding/project');
    }

    // --- Project ---

    public function project(): void
    {
        $this->view('project');
    }

    public function submit_project(): void
    {
        $customer = $this->model->_get_current_customer();
        if (!$customer) {
            redirect('customer/login');
        }

        $teams = $this->model->get_owned_teams($customer->id);
        if (empty($teams)) {
            redirect('customer-onboarding/team');
        }
        $team_id = $teams[0]->id;

        $this->validation->set_rules('label', 'Project Name', 'required|min_length[3]|max_length[50]');
        $this->validation->set_rules('type', 'Project Type', 'required');

        if ($this->validation->run() === false) {
            $this->project();
            return;
        }

        $label = post('label', true);
        $type = post('type', true);

        $this->model->db->transaction(function () use ($team_id, $label, $type, $customer) {
            $project_id = $this->model->create_project($team_id, $label, $type ?? 'trongate_v1');
            $updated = $this->model->mark_onboarded($customer->id);

            return $project_id !== false && $updated;
        });

        redirect('customer');
    }

    // --- Validation Callbacks ---

    public function _validate_hetzner_token(string $token): bool|string
    {
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

    public function _validate_github_token(string $token): bool|string
    {
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

    /**
     * Ensure the current user has appropriate access permissions.
     */
    public function _make_sure_allowed(): ?string
    {
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
