<?php

/**
 * Services Controller
 * Registry and factory for source control services (GitHub, through the GitHub App)
 *
 * @property-read Secrets $secrets
 */
class Services extends Trongate {

    // provider => type
    const PROVIDERS = [
        'github' => 'source',
    ];

    function __construct(?string $module_name = null) {
        parent::__construct($module_name);
        block_url('services');
    }

    function exists(string $provider): bool {
        return isset(self::PROVIDERS[$provider]);
    }

    /**
     * Build a client for a provider
     * @return Github
     */
    function make(string $provider, string $token): Client {
        if (!$this->exists($provider)) {
            throw new InvalidArgumentException("Unknown provider: {$provider}");
        }

        $class = $this->_load($provider);
        return new $class($token);
    }

    /**
     * Build a client with the customer's GitHub user access token (saved when
     * they signed in with or connected GitHub), renewing it when it has expired.
     * Without a usable token the client has none, and its calls fail with 401.
     * @return Github
     */
    function for_customer(int $customer_id, string $provider): Client {
        $secret = $this->secrets->get('customer', $customer_id, $provider);

        if (!empty($secret['expires_at']) && (int) $secret['expires_at'] < time() + 60) {
            $secret = $this->_refresh_github_token($customer_id, $secret);
        }

        return $this->make($provider, $secret['token'] ?? '');
    }

    /**
     * Save a customer's GitHub user access token (Github_app::exchange_code/refresh)
     */
    function save_github_token(int $customer_id, array $token): bool {
        return $this->secrets->save('customer', $customer_id, 'github', $token);
    }

    /**
     * Renew an expired user access token, or forget it when GitHub refuses the
     * refresh (the customer then connects GitHub again)
     */
    function _refresh_github_token(int $customer_id, array $secret): array {
        $app = $this->github_app();

        try {
            if (empty($secret['refresh_token'])) {
                throw new Client_Error('No refresh token', 401);
            }

            $token = $app->refresh((string) $secret['refresh_token']);
            $this->save_github_token($customer_id, $token);

            return $token;
        } catch (Client_Error $e) {
            $this->secrets->delete('customer', $customer_id, 'github');

            return [];
        }
    }

    function github_app(): Github_app {
        $this->_load('github_app');
        return Github_app::from_env();
    }

    function github(string $token): Github {
        $this->_load('github');
        return new Github($token);
    }

    /**
     * Require a provider's client class and return its name
     */
    function _load(string $provider): string {
        $class = ucfirst($provider);
        require_once __DIR__ . '/clients/Client.php';
        require_once __DIR__ . "/clients/{$class}.php";
        return $class;
    }
}
