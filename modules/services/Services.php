<?php

/**
 * Services Controller
 * Registry and factory for cloud and source control services
 * 
 * @property-read Secrets $secrets
 */
class Services extends Trongate
{

    public function __construct(?string $module_name = null)
    {
        parent::__construct($module_name);
        block_url_invocation($module_name);
    }

    /**
     * Get Hetzner Cloud client
     * @return Hetzner
     */
    public function hetzner(string $token): Hetzner
    {
        require_once __DIR__ . '/clients/Hetzner.php';
        return new Hetzner($token);
    }

    /**
     * Get GitHub client
     * @return Github
     */
    public function github(string $token): Github
    {
        require_once __DIR__ . '/clients/Github.php';
        return new Github($token);
    }
}
