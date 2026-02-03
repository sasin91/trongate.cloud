<?php

/**
 * @property-read Customer_model $model
 * @property-read Trongate_tokens $trongate_tokens
 **/
class Customer extends Trongate {
    const CUSTOMER_USER_LEVEL = 2;

    function index(): void {
        $this->_make_sure_allowed();

        $data = [
            'view_module' => 'customer',
            'view_file' => 'index',
            'projects' => $this->model->recent_projects(),
            // Add any additional CSS/JS inclusions specific to this page here
            // 'additional_includes_top' => ['customer_module/css/custom.css']
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function login(): void {
        $data['form_location'] = 'customer/submit_login';
        $this->trongate_tokens->destroy();
        $this->_make_sure_login_attempt_allowed();
        $this->view('login', $data);
    }

    function register(): void {
        $data['form_location'] = 'customer/submit_register';
        $this->view('register', $data);
    }

    function submit_register(): void {
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
            $this->_log_user_in($data['email'], 1);
        } else {
            $this->register();
        }
    }

    function _email_check(string $email) {
        $exists = $this->model->email_exists($email);
        if ($exists) {
            return 'This email is already registered.';
        }
        return true;
    }

    function submit_login(): void {
        $this->validation->set_rules('email', 'email', 'required|valid_email|callback_login_check');
        $this->validation->set_rules('password', 'password', 'required|min_length[5]');

        $result = $this->validation->run();
        $email = post('email', true);

        if ($result === true) {
            $remember = (int) (bool) post('remember', true);
            $this->_log_user_in($email, $remember);
        } else {
            $should_block = $this->model->increment_failed_login_attempts($email);
            if ($should_block) {
                unset($_SESSION['form_submission_errors']);
                redirect('customer/not_allowed');
            }
            $this->login();
        }
    }

    function _log_user_in(string $email, int $remember): void {
        $token = $this->model->log_user_in($email, $remember);

        if ($token === false) {
            http_response_code(500);
            $fail_msg = (ENV === 'dev') ? 'Could not find record with email: ' . $email : '';
            die($fail_msg);
        }

        // Store customer ID in session for quick lookup
        $customer = $this->model->_find_one('email', $email);
        if ($customer !== false) {
            $_SESSION['customer_id'] = (int) $customer->id;
        }

        $this->model->after_login_tasks($email);
        $is_mx_request = from_trongate_mx();

        if ($is_mx_request === true) {
            http_response_code(200);
            echo $token;
            die();
        }

        redirect('customer-onboarding');
    }

    function _login_check($submitted_username) {
        $submitted_password = post('password');
        $validate_credentials = $this->model->validate_credentials($submitted_username, $submitted_password);
        if ($validate_credentials === true) {
            return true;
        }
        return 'You did not enter a correct username and/or password.';
    }

    function _make_sure_login_attempt_allowed(): void {
        $this->model->remove_expired_restrictions();
        $login_attempt_allowed = $this->model->is_login_attempt_allowed();
        if ($login_attempt_allowed !== true) {
            redirect('customer/not_allowed');
        }
    }

    function _make_sure_allowed(): ?string {
        $token = $this->trongate_tokens->attempt_get_valid_token(2);

        if ($token === false) {
            redirect('customer/login');
        }

        $customer = $this->model->_get_current_customer();

        if ($customer === false) {
            redirect('customer/login');
        }

        if ($customer->active === false || $customer->onboarded_at === null) {
            redirect('customer-onboarding');
        }

        return $token;
    }

    function logout(): void {
        $this->module('trongate_tokens');
        $this->trongate_tokens->destroy();
        redirect('customer/login');
    }

    function switch_team(): void {
        $this->_make_sure_allowed();
        
        $team_id = (int) post('team_id');
        
        if ($team_id > 0) {
            $this->model->set_current_team($team_id);
        }

        // Redirect back to referrer or dashboard
        $referrer = $_SERVER['HTTP_REFERER'] ?? BASE_URL . 'customer';
        redirect($referrer);
    }
}
