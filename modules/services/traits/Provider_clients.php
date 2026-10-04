<?php

/**
 * Provider client helpers for controllers.
 *
 * @property-read Services $services
 * @property-read Secrets $secrets
 */
trait Provider_clients {

    /**
     * Get a client with the customer's GitHub sign-in (GitHub is the only provider)
     * @return Github
     */
    function _provider_client(int $customer_id, string $provider): Client {
        return $this->services->for_customer($customer_id, $provider);
    }

    /**
     * First registered provider of a type (e.g. 'source') the customer has connected
     */
    function _customer_provider(int $customer_id, string $type): string|false {
        foreach ($this->services::PROVIDERS as $provider => $provider_type) {
            if ($provider_type === $type && $this->secrets->exists('customer', $customer_id, $provider)) {
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
}
