<?php

require_once __DIR__ . '/Impersonation_rules.php';

/**
 * Sign in to the panel as a customer from tg-admin, without their password.
 *
 * The admin keeps their own token in the session; the customer token goes in
 * the trongatetoken cookie, where a customer's own sign-in lives, and lasts
 * Impersonation_rules::MINUTES. $_SESSION['impersonation'] remembers who is
 * signed in as whom, and the customer cookie it replaced, so stop() can put
 * that back. Start and stop are written to the platform's logs.
 *
 * @property-read Impersonation_model $model
 * @property-read Trongate_security $trongate_security
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Validation $validation
 */
class Impersonation extends Trongate {

    const CUSTOMER_USER_LEVEL = 2;

    /**
     * MX modal from customer-admin/show/{id}: what signing in as the customer does
     */
    function confirm(): void {
        $this->trongate_security->make_sure_allowed();
        $customer = $this->_customer_or_back(segment(3, 'int'));

        $data = [
            'customer_id' => (int) $customer->id,
            'customer_label' => Impersonation_rules::customer_label($customer),
            'minutes' => Impersonation_rules::MINUTES,
        ];
        $this->view('confirm', $data);
    }

    /**
     * POST impersonation/start/{customer id}: sign in as the customer, then go to their dashboard
     */
    function start(): void {
        $admin_token = $this->trongate_security->make_sure_allowed();
        $this->_require_post_with_csrf();
        $customer = $this->_customer_or_back(segment(3, 'int'));

        $refusal = Impersonation_rules::refusal($customer);

        if ($refusal !== null) {
            set_flashdata($refusal);
            redirect('customer-admin/show/' . $customer->id);
        }

        $previous_token = $this->_end_earlier_impersonation();
        $expiry_date = time() + 60 * Impersonation_rules::MINUTES;
        $token = $this->model->create_customer_token((int) $customer->trongate_user_id, $expiry_date);

        // Keep the admin's own sign-in in the session, where the cookie cannot replace it
        $_SESSION['trongatetoken'] = $admin_token;
        setcookie('trongatetoken', $token, $expiry_date, '/');

        $admin = $this->model->admin_username($admin_token);
        $customer_label = Impersonation_rules::customer_label($customer);
        $_SESSION['impersonation'] = [
            'customer_id' => (int) $customer->id,
            'customer_label' => $customer_label,
            'admin' => $admin,
            'token' => $token,
            'previous_token' => $previous_token,
            'started_at' => time(),
            'expires_at' => $expiry_date,
        ];

        error_log(Impersonation_rules::started_line($admin, $customer_label, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')));
        redirect('dashboard');
    }

    /**
     * POST impersonate/stop (the panel's banner): sign out of the customer
     * and go back to their tg-admin page
     */
    function stop(): void {
        $this->_require_post_with_csrf();
        $state = $_SESSION['impersonation'] ?? null;

        if ($state === null) {
            redirect('tg-admin');
        }

        $this->_end_impersonation($state);
        error_log(Impersonation_rules::stopped_line($state['admin'], $state['customer_label'], (int) $state['started_at'], time()));

        set_flashdata('You are no longer signed in as ' . $state['customer_label'] . '.');
        redirect('customer-admin/show/' . $state['customer_id']);
    }

    /**
     * For the panel template: the banner's text while the browser is signed
     * in as a customer from tg-admin, or null
     *
     * @return array{customer_label: string, admin: string, until: string}|null
     */
    function _banner(): ?array {
        $state = $_SESSION['impersonation'] ?? null;

        if ($state === null) {
            return null;
        }

        $this->module('trongate_tokens');

        if ($this->trongate_tokens->attempt_get_valid_token(self::CUSTOMER_USER_LEVEL) !== $state['token']) {
            return null;
        }

        return [
            'customer_label' => $state['customer_label'],
            'admin' => $state['admin'],
            'until' => date('H:i', (int) $state['expires_at']) . ' UTC',
        ];
    }

    /**
     * End an impersonation started earlier in this session (a second Sign in
     * without Stop), and give the customer cookie from before the first one
     */
    private function _end_earlier_impersonation(): ?string {
        $state = $_SESSION['impersonation'] ?? null;

        if ($state === null) {
            return $_COOKIE['trongatetoken'] ?? null;
        }

        $this->model->delete_token($state['token']);
        error_log(Impersonation_rules::stopped_line($state['admin'], $state['customer_label'], (int) $state['started_at'], time()));
        unset($_SESSION['impersonation']);

        return $state['previous_token'];
    }

    /**
     * Delete the customer token and give back the cookie it replaced, while
     * that one is still valid
     */
    private function _end_impersonation(array $state): void {
        $this->model->delete_token($state['token']);
        unset($_SESSION['impersonation']);

        $previous_token = (string) ($state['previous_token'] ?? '');
        $previous_expiry = $previous_token === '' ? null : $this->model->token_expiry($previous_token);

        if ($previous_expiry === null) {
            setcookie('trongatetoken', 'x', time() - 86400, '/');
            return;
        }

        setcookie('trongatetoken', $previous_token, $previous_expiry, '/');
    }

    private function _customer_or_back(int $customer_id): object {
        $customer = $customer_id > 0 ? $this->model->find_customer($customer_id) : false;

        if ($customer === false) {
            redirect('customer-admin/manage');
        }

        return $customer;
    }

    /**
     * Only accept POSTs carrying a valid CSRF token (Validation::run() blocks the request otherwise).
     */
    private function _require_post_with_csrf(): void {
        if (REQUEST_TYPE !== 'POST') {
            http_response_code(405);
            die('Method not allowed');
        }

        $this->validation->run();
    }
}
