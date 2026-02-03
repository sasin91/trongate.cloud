<?php

/**
 * @property-read Customer_model $model
 * @property-read Trongate_tokens $trongate_tokens
 **/
class Customer extends Trongate
{
    const CUSTOMER_USER_LEVEL = 2;

    public function index(): void
    {
        $this->_make_sure_allowed();

        $this->view('index', [
            'applications' => $this->model->recent_applications(),
            'servers' => $this->model->recent_servers(),
            'deployments' => $this->model->recent_deployments()
        ]);
    }

    /**
     * Display the login form.
     *
     * Prepares the login form by:
     * - Resolving the correct form submission URL
     * - Destroying any existing user tokens (logging out)
     * - Checking that login attempts are allowed
     * - Rendering the 'login' view with the prepared data
     *
     * @return void
     */
    public function login(): void
    {
        $data['form_location'] = 'customer/submit_login';
        $this->trongate_tokens->destroy(); // Log user out.
        $this->make_sure_login_attempt_allowed();
        $this->view('login', $data);
    }

    /**
     * Display the registration form.
     * 
     * @return void
     */
    public function register(): void
    {
        $data['form_location'] = 'customer/submit_register';
        $this->view('register', $data);
    }

    /**
     * Handle registration form submission.
     * 
     * @return void
     */
    public function submit_register(): void
    {
        $this->validation->set_rules('email', 'email', 'required|valid_email|max_length[255]|callback_email_check');
        $this->validation->set_rules('password', 'password', 'required|min_length[5]|max_length[60]');
        $this->validation->set_rules('password_confirm', 'repeat password', 'required|matches[password]');

        $result = $this->validation->run();

        if ($result === true) {
            $data = [
                'email' => post('email', true),
                'password' => post('password')
            ];

            $this->model->create_account($data);
            $this->log_user_in($data['email'], 1);
            redirect('customer');
        } else {
            $this->register();
        }
    }

    /**
     * Callback to check if email already exists.
     * 
     * @param string $email
     * @return string|bool
     */
    public function _email_check(string $email)
    {
        $exists = $this->model->email_exists($email);

        if ($exists) {
            return 'This email is already registered.';
        }

        return true;
    }

    /**
     * Handle login form submission
     * 
     * @return void
     */
    public function submit_login(): void
    {
        $this->validation->set_rules('email', 'email', 'required|valid_email|callback_login_check');
        $this->validation->set_rules('password', 'password', 'required|min_length[5]');

        $result = $this->validation->run();
        $email = post('email', true);

        if ($result === true) {
            $remember = (int) (bool) post('remember', true);
            $this->log_user_in($email, $remember);
        } else {
            // Handle failed login
            $should_block = $this->model->increment_failed_login_attempts($email);

            if ($should_block) {
                // Clear validation errors before blocking
                unset($_SESSION['form_submission_errors']);
                redirect('customer/not_allowed');
            }

            $this->login();
        }
    }

    /**
     * Log a user in and handle the authentication response.
     * 
     * @param string $email The email to authenticate
     * @param int $remember 0 for session-only, 1 for persistent cookie (30 days)
     * @return void
     */
    public function log_user_in(string $email, int $remember): void
    {
        $token = $this->model->log_user_in($email, $remember);

        if ($token === false) {
            http_response_code(500);
            $fail_msg = (ENV === 'dev') ? 'Could not find record with email: ' . $email : '';
            die($fail_msg);
        }

        // Reset failed login counters and lockout information.
        $this->model->after_login_tasks($email);

        $is_mx_request = from_trongate_mx();

        if ($is_mx_request === true) {
            http_response_code(200);
            echo $token;
            die();
        }

        redirect('customer');
    }

    public function _login_check($submitted_username)
    {
        $submitted_password = post('password');
        $validate_credentials = $this->model->validate_credentials($submitted_username, $submitted_password);

        if ($validate_credentials === true) {
            return true;
        }

        return 'You did not enter a correct username and/or password.';
    }

    /**
     * Ensure login attempts are permitted before proceeding with authentication.
     * 
     * @return void
     */
    private function make_sure_login_attempt_allowed(): void
    {
        $this->model->remove_expired_restrictions();
        $login_attempt_allowed = $this->model->is_login_attempt_allowed();

        if ($login_attempt_allowed !== true) {
            redirect('customer/not_allowed');
        }
    }

    /**
     * Ensure the current user has appropriate access permissions.
     * 
     * @return string|null The authentication token if access is granted, null otherwise
     */
    public function _make_sure_allowed(): ?string
    {
        $token = $this->trongate_tokens->attempt_get_valid_token(2);

        // Handle API/MX requests
        if (from_trongate_mx()) {
            if ($token === false) {
                http_response_code(401);
            } else {
                http_response_code(200);
                echo $token;
            }

            die();
        }

        // Handle web requests with no valid token
        if ($token === false) {
            redirect('customer/login');
        }

        return $token;
    }
    /**
     * Log the current user out.
     * 
     * @return void
     */
    public function logout(): void
    {
        $this->module('trongate_tokens');
        $this->trongate_tokens->destroy();
        redirect('customer/login');
    }
}
