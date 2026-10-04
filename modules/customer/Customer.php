<?php

require_once __DIR__ . '/traits/Customer_guard.php';
require_once APPPATH . 'modules/platform/traits/Live_app_state.php';

/**
 * Login, logout and lockouts are handled by the login module (user level 2).
 *
 * @property-read Customer_model $model
 * @property-read Login $login
 * @property-read Templates $templates
 * @property-read Validation $validation
 * @property-read Trongate_tokens $trongate_tokens
 */
class Customer extends Trongate {
    use Customer_guard;
    use Live_app_state;

    const CUSTOMER_USER_LEVEL = 2;

    function index(): void {
        $this->_make_sure_allowed();

        $project_id = $this->_project_id_or_redirect();
        $apps = $this->model->project_apps($project_id);
        $live_states = $this->_live_app_states($project_id);

        foreach ($apps as $app) {
            $app->live_state = $this->_live_state_of($live_states, $app);
        }

        $data = [
            'view_module' => 'customer',
            'view_file' => 'index',
            'apps' => $apps,
            'additional_includes_top' => [
                'deployments_module/css/deployments.css',
                'apps_module/css/apps.css',
            ],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function register(): void {
        $data['form_location'] = 'register/submit';
        $this->view('register', $data);
    }

    function submit_register(): void {
        $this->validation->set_rules('email', 'email', 'required|valid_email|max_length[255]|callback__email_check');
        $this->validation->set_rules('password', 'password', 'required|min_length[5]|max_length[60]');
        $this->validation->set_rules('password_confirm', 'repeat password', 'required|matches[password]');

        if ($this->validation->run() !== true) {
            $this->register();
            return;
        }

        $email = post('email', true);

        $this->module('login');
        $this->model->create_account($email, $this->login->hash_password(post('password')));
        $this->login->model->log_user_in($email, self::CUSTOMER_USER_LEVEL, 1);

        redirect('onboarding');
    }

    function _email_check(string $email): bool|string {
        if ($this->model->email_exists($email)) {
            return 'This email is already registered.';
        }
        return true;
    }

    function switch_project(): void {
        $this->_make_sure_allowed();

        if (REQUEST_TYPE !== 'POST') {
            http_response_code(405);
            die('Method not allowed');
        }

        // No rules: run() only enforces the CSRF token from form_close()
        $this->validation->run();

        $project_id = (int) post('project_id');

        if ($project_id > 0) {
            $this->model->set_current_project($project_id);
        }

        // Redirect back to referrer (same site only) or dashboard
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';
        redirect(str_starts_with($referrer, BASE_URL) ? $referrer : BASE_URL . 'dashboard');
    }
}
