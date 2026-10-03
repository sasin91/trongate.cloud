<?php

/**
 * Services Controller
 * Registry and factory for source control services
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
     * Build a client using the team's stored provider token
     * @return Github
     */
    function for_team(int $team_id, string $provider): Client {
        $secret = $this->secrets->get('team', $team_id, $provider);
        return $this->make($provider, $secret['token'] ?? '');
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
