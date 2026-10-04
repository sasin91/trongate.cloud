<?php

/**
 * Sign in with GitHub, through the trongate.cloud GitHub App's OAuth client.
 *
 *   auth/github            start: sign in, sign up, or (when signed in) connect GitHub
 *   auth/github/callback   GitHub returns here with ?code&state
 *   auth/github/installed  the App's Setup URL, after it is installed or its repositories change
 *
 * Signed out, the GitHub account signs in the customer it is linked to, or
 * creates one with its verified primary email. An email that already has a
 * password account is not taken over: that customer signs in with their
 * password and connects GitHub from there. Signed in, the GitHub account is
 * linked to the current customer.
 *
 * The user access token is kept (secrets: customer, {id}, github) to list the
 * repositories the customer may pick. Builds read repositories with the App's
 * installation tokens instead, so they do not depend on anyone staying signed in.
 *
 * The OAuth state is an HMAC-signed value (APP_KEY) tied to a cookie nonce, so
 * it survives the round trip whichever app pod answers the callback.
 *
 * @property-read Github_auth_model $model
 * @property-read Services $services
 * @property-read Login $login
 */
class Github_auth extends Trongate {

    const STATE_COOKIE = 'tc_github_state';

    const RETURN_COOKIE = 'tc_github_return';

    /** Seconds the customer has to finish on GitHub */
    const STATE_TTL_SECONDS = 600;

    const DEFAULT_RETURN = 'onboarding';

    /** config/login.php, user level 2 */
    const CUSTOMER_LEVEL = 2;

    function start(): void {
        $app = $this->services->github_app();

        if (!$app->can_sign_in()) {
            set_flashdata('Signing in with GitHub is not available yet.');
            redirect('login');
        }

        $nonce = bin2hex(random_bytes(16));
        $this->_set_cookie(self::STATE_COOKIE, $nonce);
        $this->_set_cookie(self::RETURN_COOKIE, $this->_safe_return((string) ($_GET['then'] ?? '')));

        redirect($app->authorize_url($this->_sign_state($nonce), self::callback_url()));
    }

    function callback(): void {
        $state_ok = $this->_verify_state((string) ($_GET['state'] ?? ''), (string) ($_COOKIE[self::STATE_COOKIE] ?? ''));
        $this->_set_cookie(self::STATE_COOKIE, '', -1);

        if (!$state_ok) {
            $this->_fail('That GitHub sign-in link has expired. Please try again.');
        }

        $code = (string) ($_GET['code'] ?? '');

        if (isset($_GET['error']) || $code === '') {
            $this->_fail('GitHub sign-in was cancelled.');
        }

        $app = $this->services->github_app();

        try {
            $token = $app->exchange_code($code, self::callback_url());
            $github = $this->services->github($token['token']);
            $user = $github->user();
        } catch (Client_Error $e) {
            $this->_fail('GitHub did not let us sign you in. Please try again.');
        }

        $github_user_id = (int) ($user['id'] ?? 0);
        $github_login = (string) ($user['login'] ?? '');

        if ($github_user_id <= 0) {
            $this->_fail('GitHub did not tell us who you are. Please try again.');
        }

        $current = $this->model->_get_current_customer();
        $linked = $this->model->find_by_github_id($github_user_id);

        if ($current !== false) {
            // Connecting GitHub to the signed-in account
            if ($linked !== false && (int) $linked->id !== (int) $current->id) {
                $this->_fail('That GitHub account already belongs to another trongate.cloud account.');
            }

            $customer = $current;
        } elseif ($linked !== false) {
            $customer = $linked;
        } else {
            $customer = $this->_sign_up($github, $github_user_id, $github_login);
        }

        if (empty($customer->active)) {
            $this->_fail('This account is disabled.');
        }

        $this->model->link_github((int) $customer->id, $github_user_id, $github_login);
        $this->services->save_github_token((int) $customer->id, $token);

        if ($current === false) {
            $this->model->log_in($customer, $this->_remember_days());
        }

        // No installation yet: nothing to pick from, so install the App first
        if (GITHUB_APP_SLUG !== '' && !$this->_has_installation($github)) {
            redirect($app->install_url());
        }

        $this->_return();
    }

    /**
     * The App's Setup URL: GitHub sends the customer here after installing the
     * App or changing which repositories it may read.
     */
    function installed(): void {
        if ($this->model->_get_current_customer() === false) {
            redirect('login');
        }

        $this->_return();
    }

    static function callback_url(): string {
        return BASE_URL . 'auth/github/callback';
    }

    /**
     * Create a customer for a GitHub account that is linked to none.
     */
    function _sign_up(Github $github, int $github_user_id, string $github_login): object {
        try {
            $email = $github->primary_email();
        } catch (Client_Error $e) {
            $email = null;
        }

        if ($email === null || strlen($email) > 255) {
            $this->_fail('Your GitHub account needs a verified primary email address to sign up.');
        }

        if ($this->model->email_exists($email)) {
            $this->_fail('An account with ' . htmlspecialchars($email) . ' already exists. Sign in with your password, then connect GitHub.');
        }

        $customer_id = $this->model->create_github_account($email, $github_user_id, $github_login);
        $customer = $customer_id === false ? false : $this->model->find_customer((int) $customer_id);

        if ($customer === false) {
            $this->_fail('Your account could not be created. Please try again.');
        }

        return $customer;
    }

    function _has_installation(Github $github): bool {
        try {
            return (int) ($github->request('GET', '/user/installations?per_page=1')['total_count'] ?? 0) > 0;
        } catch (Client_Error $e) {
            // Unknown: let the repository picker say what is missing
            return true;
        }
    }

    function _remember_days(): int {
        $this->module('login');

        return (int) ($this->login->model->get_level_config(self::CUSTOMER_LEVEL)['remember_days'] ?? 30);
    }

    /**
     * Back to where the customer started (signed in), or to the login page with a message
     */
    function _fail(string $message): never {
        set_flashdata($message);
        redirect($this->model->_get_current_customer() !== false ? $this->_return_path() : 'login');
    }

    function _return(): never {
        $path = $this->_return_path();
        $this->_set_cookie(self::RETURN_COOKIE, '', -1);
        redirect($path);
    }

    function _return_path(): string {
        return $this->_safe_return((string) ($_COOKIE[self::RETURN_COOKIE] ?? ''));
    }

    /**
     * A path inside the control panel (e.g. projects/3/apps/new), never another host
     */
    function _safe_return(string $path): string {
        return preg_match('#^[a-z0-9][a-z0-9/_-]{0,199}$#i', $path) ? $path : self::DEFAULT_RETURN;
    }

    // --- OAuth state ---------------------------------------------------------

    function _sign_state(string $nonce): string {
        $payload = $nonce . '.' . (time() + self::STATE_TTL_SECONDS);

        return $payload . '.' . hash_hmac('sha256', 'github-state:' . $payload, $this->_state_key());
    }

    function _verify_state(string $state, string $cookie_nonce): bool {
        $parts = explode('.', $state);

        if (count($parts) !== 3 || $cookie_nonce === '') {
            return false;
        }

        [$nonce, $expires, $mac] = $parts;
        $expected = hash_hmac('sha256', 'github-state:' . $nonce . '.' . $expires, $this->_state_key());

        return hash_equals($expected, $mac)
            && hash_equals($nonce, $cookie_nonce)
            && (int) $expires >= time();
    }

    function _state_key(): string {
        if (APP_KEY === '') {
            throw new RuntimeException('APP_KEY is required to sign in with GitHub');
        }

        return APP_KEY;
    }

    /**
     * A short-lived cookie for the GitHub round trip. SameSite=Lax: GitHub's
     * redirect back is a top-level GET, which carries it.
     */
    function _set_cookie(string $name, string $value, int $ttl = self::STATE_TTL_SECONDS): void {
        setcookie($name, $value, [
            'expires' => time() + $ttl,
            'path' => '/',
            'secure' => str_starts_with(BASE_URL, 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
