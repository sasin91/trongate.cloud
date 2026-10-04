<?php

require_once __DIR__ . '/../traits/Customer_guard.php';
require_once APPPATH . 'modules/services/traits/Provider_clients.php';
require_once APPPATH . 'modules/projects/traits/Project_form.php';

/**
 * Onboarding: GitHub token -> first project (which queues its first deploy).
 * The account's team is created silently on the way in.
 *
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Onboarding_model $model
 * @property-read Validation $validation
 * @property-read Services $services
 * @property-read Secrets $secrets
 */
class Onboarding extends Trongate {
    use Customer_guard;
    use Provider_clients;
    use Project_form;

    function __construct(string $module_name = '') {
        parent::__construct($module_name);
        $this->_make_sure_allowed();
    }

    function index(): void {
        $team_id = $this->_team_id_or_create();

        if ($this->_team_provider($team_id, 'source') === false) {
            redirect('onboarding/source');
        }

        redirect('onboarding/project');
    }

    // --- Team ---

    /**
     * Customers never name a team: each account gets one behind the scenes,
     * which owns its apps and GitHub connection.
     */
    function team(): void {
        $this->_team_id_or_create();
        redirect('onboarding/source');
    }

    function _team_id_or_create(): int {
        $team_id = $this->model->_get_current_team_id();

        if ($team_id !== false) {
            return $team_id;
        }

        $customer = $this->_customer_or_redirect();
        $name = strstr((string) $customer->email, '@', true) ?: 'Personal';

        $team_id = $this->model->create_team((int) $customer->id, $name);
        $this->model->add_team_member($team_id, (int) $customer->id, 'owner');
        $this->model->set_current_team($team_id);

        return $team_id;
    }

    // --- Source Control (GitHub) ---

    function source(): void {
        $this->_team_id_or_create();
        $this->view('source');
    }

    function submit_source(): void {
        $team_id = $this->_team_id_or_redirect();

        $this->validation->set_rules('provider', 'Source Control Provider', 'required|callback__validate_source_provider');
        $this->validation->set_rules('token', 'Personal Access Token', 'required|min_length[10]|callback__validate_github_token');

        if ($this->validation->run() === false) {
            $this->source();
            return;
        }

        $this->secrets->save('team', $team_id, post('provider', true), ['token' => post('token', true)]);

        redirect('onboarding/project');
    }

    // --- Project ---

    function project(): void {
        $team_id = $this->_team_id_or_redirect();
        $form = $this->_project_form_data($team_id);

        if ($form === false) {
            redirect('onboarding/source');
        }

        $this->view('project', $form);
    }

    function submit_project(): void {
        $team_id = $this->_team_id_or_redirect();

        $this->_set_project_rules();

        if ($this->validation->run() === false) {
            $this->project();
            return;
        }

        $customer = $this->_customer_or_redirect();

        $project_id = $this->model->db->transaction(function () use ($team_id, $customer) {
            $project_id = $this->_create_project_from_post($team_id);

            if ($project_id === false || !$this->model->mark_onboarded((int) $customer->id)) {
                return false;
            }

            return $project_id;
        });

        if ($project_id === false) {
            redirect('onboarding/project');
        }

        // Queued after the transaction commits (queue_deploy opens its own)
        $deployment_id = $this->_queue_first_deploy($team_id, (int) $project_id);

        redirect($deployment_id === false ? 'projects/' . $project_id : 'deployments/' . $deployment_id);
    }

    /**
     * Inverse of the customer guard: only logged in customers who are NOT yet onboarded.
     */
    function _make_sure_allowed(): string {
        $token = $this->trongate_tokens->attempt_get_valid_token(2);

        if ($token === false) {
            redirect('login');
        }

        $customer = $this->model->_get_current_customer();

        if ($customer === false) {
            redirect('login');
        }

        if (!empty($customer->active) && $customer->onboarded_at !== null) {
            redirect('dashboard');
        }

        return $token;
    }
}
