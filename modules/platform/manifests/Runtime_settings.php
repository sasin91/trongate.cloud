<?php

require_once __DIR__ . '/Build_commands.php';

/**
 * How an app is built and run (the app_runtime table), read from an app row
 * that carries its columns (Platform_task::app, find_project_app,
 * find_member_app, project_apps join them). Each method has its column's
 * name and gives the default when the app has no app_runtime row or the
 * value is invalid, so the panel and the worker read them the same way.
 *
 * Framework-free: the panel and the worker both load it.
 */
final class Runtime_settings {

    const DEFAULT_TRONGATE_ENV = 'dev';

    /** Offered on the app's Settings page; any other value matching TRONGATE_ENV_PATTERN works too */
    const TRONGATE_ENV_SUGGESTIONS = ['dev', 'staging', 'prod'];

    const TRONGATE_ENV_PATTERN = '/^[A-Za-z0-9_-]{1,20}$/';

    /** An app's health check path (health_check_path()): absolute, no query, no dot or empty segments */
    const HEALTH_CHECK_PATH_PATTERN = '#^(?!.*//)(?!.*/\.{1,2}(?:/|$))/[A-Za-z0-9._~/-]{0,199}$#';

    /**
     * Extra PHP extensions for the image build (a JSON list): lowercase,
     * unique, only names install-php-extensions takes
     *
     * @return list<string>
     */
    static function php_extensions(array $app): array {
        $names = $app['php_extensions'] ?? [];

        if (is_string($names)) {
            $names = json_decode($names, true) ?: [];
        }

        $clean = [];

        foreach ((array) $names as $name) {
            $name = strtolower(trim((string) $name));

            if (preg_match('/^[a-z0-9_]{1,40}$/', $name)) {
                $clean[$name] = $name;
            }
        }

        return array_values($clean);
    }

    /**
     * Shell lines the image build runs after composer (Build_commands); ''
     * when none
     */
    static function build_commands(array $app): string {
        return Build_commands::normalise((string) ($app['build_commands'] ?? ''));
    }

    /**
     * Directories kept across deploys (a JSON list), relative to the app
     * dir; rejects anything that escapes it
     *
     * @return list<string>
     */
    static function persistent_paths(array $app): array {
        $paths = $app['persistent_paths'] ?? [];

        if (is_string($paths)) {
            $paths = json_decode($paths, true) ?: [];
        }

        $clean = [];

        foreach ((array) $paths as $path) {
            $path = trim(str_replace('\\', '/', (string) $path), '/');

            if ($path === '') {
                continue;
            }

            if (!preg_match('#^[A-Za-z0-9._/-]+$#', $path) || preg_match('#(^|/)\.{1,2}(/|$)#', $path)) {
                throw new InvalidArgumentException("Invalid persistent path: {$path}");
            }

            $clean[$path] = $path;
        }

        return array_values($clean);
    }

    /**
     * Whether the app never sleeps when idle
     */
    static function always_on(array $app): bool {
        return !empty($app['always_on']);
    }

    /**
     * Trongate's ENV constant for the app, DEFAULT_TRONGATE_ENV when unset
     * or invalid
     */
    static function trongate_env(array $app): string {
        $env = (string) ($app['trongate_env'] ?? '');

        return preg_match(self::TRONGATE_ENV_PATTERN, $env) ? $env : self::DEFAULT_TRONGATE_ENV;
    }

    /**
     * Whether the app's mail goes to the platform's inbox (Mail_capture);
     * on unless turned off
     */
    static function captures_mail(array $app): bool {
        return !array_key_exists('captures_mail', $app) || $app['captures_mail'] === null || (int) $app['captures_mail'] === 1;
    }

    /**
     * The path the rollout gate and the smoke test request: the home page
     * unless the app names another, e.g. /health for an API whose home page
     * is a 404. Anything not HEALTH_CHECK_PATH_PATTERN is the home page.
     */
    static function health_check_path(array $app): string {
        $path = trim((string) ($app['health_check_path'] ?? ''));

        return preg_match(self::HEALTH_CHECK_PATH_PATTERN, $path) ? $path : '/';
    }
}
