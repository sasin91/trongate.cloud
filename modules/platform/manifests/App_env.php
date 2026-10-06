<?php

/**
 * An app's own environment variables (e.g. API keys): stored encrypted in the
 * secrets table as (module 'app', app id, service 'env'), never shown back,
 * and written to the app's p{id}-env Secret on each deploy
 * (Manifests::app, loaded with envFrom).
 *
 * Framework-free: the apps module validates with it, the worker reads with it.
 */
final class App_env {

    const MODULE = 'app';
    const SERVICE = 'env';

    const MAX_VARIABLES = 100;
    const MAX_NAME_LENGTH = 100;
    const MAX_VALUE_BYTES = 32768;

    /** Set by the platform on the app container (Manifests::_deployment); they would be ignored */
    const RESERVED = ['PORT', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME'];

    /**
     * The worker's Module_reference for the app's variables
     */
    static function reference(int $app_id): string {
        return 'secrets:' . self::MODULE . ',' . $app_id . ',' . self::SERVICE;
    }

    /**
     * @return string|null why the name cannot be used, or null when it can
     */
    static function name_error(string $name): ?string {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || strlen($name) > self::MAX_NAME_LENGTH) {
            return 'Names may only contain letters, numbers and underscores, and may not start with a number.';
        }

        if (in_array(strtoupper($name), self::RESERVED, true)) {
            return "{$name} is set by the platform and cannot be changed.";
        }

        return null;
    }
}
