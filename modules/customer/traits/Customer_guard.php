<?php

/**
 * Access guard for customer-facing controllers.
 * The controller's model must use the Current_customer trait.
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
     * Get the current team ID or redirect to team creation
     */
    function _team_id_or_redirect(): int {
        $team_id = $this->model->_get_current_team_id();

        if ($team_id === false) {
            redirect('onboarding/team');
        }

        return $team_id;
    }
}
