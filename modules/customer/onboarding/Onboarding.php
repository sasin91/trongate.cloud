<?php

require_once __DIR__ . '/../traits/Customer_guard.php';
require_once APPPATH . 'modules/services/traits/Provider_clients.php';
require_once APPPATH . 'modules/apps/traits/App_form.php';

/**
 * Onboarding: project -> GitHub token -> first app (which queues its first deploy).
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
    use App_form;

    function __construct(string $module_name = '') {
        parent::__construct($module_name);
        $this->_make_sure_allowed();
    }

    function index(): void {
        $project_id = $this->_project_id_or_redirect();

        if ($this->_project_provider($project_id, 'source') === false) {
            redirect('onboarding/source');
        }

        redirect('onboarding/app');
    }

    // --- Project ---

    function project(): void {
        // Coming back to this question (browser Back) shows the project they already named
        $project_id = $this->model->_get_current_project_id();
        $projects = $project_id === false ? [] : array_column($this->model->get_customer_projects(), 'name', 'id');

        $this->view('project', ['project_name' => $projects[$project_id] ?? '']);
    }

    function submit_project(): void {
        $this->validation->set_rules('name', 'Project name', 'required|min_length[3]|max_length[50]');

        if ($this->validation->run() === false) {
            $this->project();
            return;
        }

        $customer = $this->_customer_or_redirect();
        $name = post('name', true);

        // Answering again renames the project instead of creating a second one
        $project_id = $this->model->_get_current_project_id();

        if ($project_id !== false) {
            $this->model->rename_project($project_id, $name);
            redirect('onboarding/source');
        }

        $project_id = $this->model->create_project($customer->id, $name);
        $this->model->add_project_member($project_id, $customer->id, 'owner');
        $this->model->set_current_project($project_id);

        redirect('onboarding/source');
    }

    // --- Source Control (GitHub) ---

    function source(): void {
        $this->view('source');
    }

    function submit_source(): void {
        $project_id = $this->_project_id_or_redirect();

        $this->validation->set_rules('provider', 'Source Control Provider', 'required|callback__validate_source_provider');
        $this->validation->set_rules('token', 'Personal Access Token', 'required|min_length[10]|callback__validate_github_token');

        if ($this->validation->run() === false) {
            $this->source();
            return;
        }

        $this->secrets->save('project', $project_id, post('provider', true), ['token' => post('token', true)]);

        redirect('onboarding/app');
    }

    // --- App ---

    function app(): void {
        $project_id = $this->_project_id_or_redirect();
        $form = $this->_app_form_data($project_id);

        if ($form === false) {
            redirect('onboarding/source');
        }

        $this->view('app', $form);
    }

    function submit_app(): void {
        $project_id = $this->_project_id_or_redirect();

        $this->_set_app_rules();

        if ($this->validation->run() === false) {
            $this->app();
            return;
        }

        $customer = $this->_customer_or_redirect();

        $app_id = $this->model->db->transaction(function () use ($project_id, $customer) {
            $app_id = $this->_create_app_from_post($project_id);

            if ($app_id === false || !$this->model->mark_onboarded((int) $customer->id)) {
                return false;
            }

            return $app_id;
        });

        if ($app_id === false) {
            redirect('onboarding/app');
        }

        // Queued after the transaction commits (queue_deploy opens its own)
        $deployment_id = $this->_queue_first_deploy($project_id, (int) $app_id);

        redirect($deployment_id === false ? 'apps/' . $app_id : 'deployments/' . $deployment_id);
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
