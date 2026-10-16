<?php

require_once __DIR__ . '/../traits/Customer_guard.php';
require_once APPPATH . 'modules/services/traits/Provider_clients.php';
require_once APPPATH . 'modules/apps/traits/App_form.php';

/**
 * Onboarding: connect GitHub (sign in through the GitHub App, install it) ->
 * first app (which queues its first deploy).
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

    /** The onboarding wizard's draft in the session (App_wizard_draft) */
    const WIZARD_DRAFT_KEY = 'onboarding';

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

    // --- Source Control (GitHub, modules/github_auth) ---

    function source(): void {
        $this->view('source', ['connect_url' => 'auth/github?then=onboarding/app', 'public_link_url' => 'onboarding/app']);
    }

    // --- App ---

    function app(): void {
        $customer = $this->_customer_or_redirect();
        $this->_forget_earlier_errors();
        $form = $this->_app_form_data((int) $customer->id, 'auth/github?then=onboarding/app', self::WIZARD_DRAFT_KEY);

        $this->view('app', $form);
    }

    /**
     * POST onboarding/draft: app_steps.js keeps the answers so far and the
     * open question, so a refresh opens the same question
     */
    function draft(): void {
        $this->_customer_or_redirect();
        $this->_require_post_with_csrf();

        $this->_save_wizard_draft(self::WIZARD_DRAFT_KEY, (string) post('wizard_step', true), post());
        http_response_code(204);
    }

    function submit_app(): void {
        $customer = $this->_customer_or_redirect();
        $project_id = $this->_onboarding_project_id((int) $customer->id);
        $this->_redirect_unless_posted('onboarding/app');

        $this->_set_app_rules();

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

        $this->_forget_wizard_draft(self::WIZARD_DRAFT_KEY);

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
