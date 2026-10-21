<?php

/**
 * Access guard for customer-facing controllers.
 * The controller's model must use the Current_customer and Platform_actions traits.
 *
 * Authorization is per request: a project is reachable while the customer is a
 * member of it, an app while they are a member of the app's project.
 * Owners additionally manage members, rename and delete.
 *
 * @property-read Trongate_tokens $trongate_tokens
 */
trait Customer_guard {

    /**
     * Ensure a logged in, active and onboarded customer.
     * @return string The valid token
     */
    function _make_sure_allowed(): string {
        $token = $this->trongate_tokens->attempt_get_valid_token(2);

        if ($token === false) {
            Modules::run('impersonation/_return_to_tg_admin_if_ended');
            redirect('login');
        }

        $customer = $this->model->_get_current_customer();

        if ($customer === false) {
            redirect('login');
        }

        if (empty($customer->active) || $customer->onboarded_at === null) {
            redirect('onboarding');
        }

        return $token;
    }

    /**
     * Get the current customer or redirect to login
     */
    function _customer_or_redirect(): object {
        $customer = $this->model->_get_current_customer();

        if ($customer === false) {
            redirect('login');
        }

        return $customer;
    }

    /**
     * A project the current customer is a member of (with member_role), or redirect to the dashboard
     */
    function _member_project_or_redirect(int $project_id): object {
        $customer = $this->_customer_or_redirect();
        $project = $project_id > 0 ? $this->model->find_member_project((int) $customer->id, $project_id) : false;

        if ($project === false) {
            redirect('dashboard');
        }

        return $project;
    }

    /**
     * An app in one of the current customer's projects (with project_name,
     * and member_role), or redirect to the dashboard
     */
    function _member_app_or_redirect(int $app_id): object {
        $customer = $this->_customer_or_redirect();
        $app = $app_id > 0 ? $this->model->find_member_app((int) $customer->id, $app_id) : false;

        if ($app === false) {
            redirect('dashboard');
        }

        return $app;
    }

    /**
     * Whether the customer owns the project of this row (a member project or member app)
     */
    function _is_owner(object $row): bool {
        return (string) ($row->member_role ?? '') === 'owner';
    }

    /**
     * Only the project's owner may continue; others go back with a message
     */
    function _make_sure_owner(object $row, string $message, string $back_to): void {
        if (!$this->_is_owner($row)) {
            set_flashdata($message);
            redirect($back_to);
        }
    }

    /**
     * Only accept POSTs carrying a valid CSRF token (Validation::run() blocks the request otherwise).
     */
    function _require_post_with_csrf(): void {
        if (REQUEST_TYPE !== 'POST') {
            http_response_code(405);
            die('Method not allowed');
        }

        $this->validation->run();
    }
}
