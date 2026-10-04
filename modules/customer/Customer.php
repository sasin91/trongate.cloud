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

    /**
     * Dashboard: every project the customer is a member of, with its apps.
     */
    function index(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();

        $projects = $this->model->member_projects((int) $customer->id);

        foreach ($projects as $project) {
            $project->apps = $this->model->project_apps((int) $project->id);
            $live_states = empty($project->apps) ? null : $this->_live_app_states($project);

            foreach ($project->apps as $app) {
                $app->live_state = $this->_live_state_of($live_states, $app);
            }
        }

        $data = [
            'view_module' => 'customer',
            'view_file' => 'index',
            'projects' => $projects,
            'additional_includes_top' => [
                'deployments_module/css/deployments.css',
                'apps_module/css/apps.css',
                'projects_module/css/projects.css',
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
}
