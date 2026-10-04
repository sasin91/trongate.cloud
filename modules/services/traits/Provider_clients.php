<?php

/**
 * Provider client helpers for controllers.
 *
 * @property-read Services $services
 * @property-read Secrets $secrets
 */
trait Provider_clients {

    /**
     * Get a client for the project's provider credentials (GitHub is the only provider)
     * @return Github
     */
    function _provider_client(int $project_id, string $provider): Client {
        return $this->services->for_project($project_id, $provider);
    }

    /**
     * First registered provider of a type (e.g. 'source') the project has credentials for
     */
    function _project_provider(int $project_id, string $type): string|false {
        foreach ($this->services::PROVIDERS as $provider => $provider_type) {
            if ($provider_type === $type && $this->secrets->exists('project', $project_id, $provider)) {
                return $provider;
            }
        }

        return false;
    }

    /**
     * Validation callback: provider must be a registered source control provider
     */
    function _validate_source_provider(string $provider): bool|string {
        if (($this->services::PROVIDERS[$provider] ?? null) !== 'source') {
            return 'Invalid provider.';
        }

        return true;
    }

    /**
     * Validation callback: the GitHub token must authenticate
     */
    function _validate_github_token(string $token): bool|string {
        if (empty($token)) {
            return 'Personal Access Token is required.';
        }

        if (!$this->services->make('github', $token)->validate_credentials()) {
            return 'Invalid token. Please check your GitHub Personal Access Token.';
        }

        return true;
    }
}
