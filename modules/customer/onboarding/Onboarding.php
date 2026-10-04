<?php

require_once __DIR__ . '/../traits/Customer_guard.php';
require_once APPPATH . 'modules/services/traits/Provider_clients.php';
require_once APPPATH . 'modules/apps/traits/App_form.php';

/**
 * Onboarding: GitHub token -> first app (which queues its first deploy).
 * The first project is created with the app and named after it; it can be renamed later.
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
        $customer = $this->_customer_or_redirect();

        // Added to someone's project before onboarding: their apps are waiting, nothing to set up
        if ($this->model->member_projects((int) $customer->id) !== [] && $this->model->mark_onboarded((int) $customer->id)) {
            redirect('dashboard');
        }

        if ($this->_customer_provider((int) $customer->id, 'source') === false) {
            redirect('onboarding/source');
        }

        redirect('onboarding/app');
    }

    // --- Source Control (GitHub) ---

    function source(): void {
        $this->view('source');
    }

    function submit_source(): void {
        $customer = $this->_customer_or_redirect();

        $this->validation->set_rules('provider', 'Source Control Provider', 'required|callback__validate_source_provider');
        $this->validation->set_rules('token', 'Personal Access Token', 'required|min_length[10]|callback__validate_github_token');

        if ($this->validation->run() === false) {
            $this->source();
            return;
        }

        $this->secrets->save('customer', (int) $customer->id, post('provider', true), ['token' => post('token', true)]);

        redirect('onboarding/app');
    }

    // --- App ---

    function app(): void {
        $customer = $this->_customer_or_redirect();
        $form = $this->_app_form_data((int) $customer->id);

        if ($form === false) {
            redirect('onboarding/source');
        }

        $this->view('app', $form);
    }

    function submit_app(): void {
        $customer = $this->_customer_or_redirect();
        $project_id = $this->_onboarding_project_id((int) $customer->id);

        $this->_set_app_rules($project_id ?? 0);

        if ($this->validation->run() === false) {
            $this->app();
            return;
        }

        // The first project is named after the first app (create_project opens its own transaction)
        $project_id ??= $this->model->create_project((int) $customer->id, post('label', true));

        $app_id = $this->model->db->transaction(function () use ($project_id, $customer) {
            $app_id = $this->_create_app_from_post($project_id, (int) $customer->id);

            if ($app_id === false || !$this->model->mark_onboarded((int) $customer->id)) {
                return false;
            }

            return $app_id;
        });

        if ($app_id === false) {
            redirect('onboarding/app');
        }

        // Queued after the transaction commits (queue_deploy opens its own)
        $deployment_id = $this->_queue_first_deploy((int) $customer->id, (int) $app_id);

        redirect($deployment_id === false ? 'apps/' . $app_id : 'deployments/' . $deployment_id);
    }

    /**
     * The project an earlier, failed attempt already created, or null when there is none yet
     */
    function _onboarding_project_id(int $customer_id): ?int {
        $projects = $this->model->member_projects($customer_id);

        return $projects === [] ? null : (int) $projects[0]->id;
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
