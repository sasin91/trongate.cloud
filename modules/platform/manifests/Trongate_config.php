<?php

require_once __DIR__ . '/../../../config/platform.php';

/**
 * Renders the Trongate config files we mount over a customer image.
 * Customer apps hardcode config and don't read env, so we edit/replace the files.
 *
 * Framework-free: loaded by the worker.
 */
class Trongate_config {

    /** ENV for an app that has not picked one: Trongate shows errors in dev */
    const DEFAULT_ENV = 'dev';

    /** Offered on the app's Settings page; any other value matching ENV_PATTERN works too */
    const ENV_SUGGESTIONS = ['dev', 'staging', 'prod'];

    const ENV_PATTERN = '/^[A-Za-z0-9_-]{1,20}$/';

    /**
     * The app's Trongate ENV constant (app.trongate_env), DEFAULT_ENV when unset or invalid
     */
    static function env(array $app): string {
        $env = (string) ($app['trongate_env'] ?? '');

        return preg_match(self::ENV_PATTERN, $env) ? $env : self::DEFAULT_ENV;
    }

    /**
     * What the platform sets in an app's config/*.php: BASE_URL and ENV
     * wherever the app defines them, and the database connection in
     * database.php (v2 apps read databases.default.*, v1 apps the
     * HOST/PORT/USER/PASSWORD/DATABASE constants).
     *
     * @param array{host: string, port: string, user: string, password: string, database: string} $db
     * @return list<Config_rule>
     */
    static function rules(array $app, array $db): array {
        require_once __DIR__ . '/Config_rule.php';

        $rules = [
            new Config_rule('BASE_URL', self::base_url($app)),
            new Config_rule('ENV', self::env($app)),
        ];
        $v1 = ($app['type'] ?? 'trongate_v2') === 'trongate_v1';

        foreach (['host', 'port', 'user', 'password', 'database'] as $key) {
            $rules[] = new Config_rule($v1 ? strtoupper($key) : "databases.default.{$key}", (string) $db[$key], 'database.php');
        }

        return $rules;
    }

    /**
     * The app's config files (file name in config/ => source) with the
     * platform's values set (rules()); only the edited files are returned,
     * and mounted over the image's.
     *
     * @return array<string, string>
     * @throws RuntimeException when a file is not valid PHP or cannot be edited
     */
    static function config_files(array $files, array $app, array $db): array {
        require_once __DIR__ . '/Config_editor.php';

        try {
            return Config_editor::apply($files, self::rules($app, $db));
        } catch (RuntimeException $e) {
            throw new RuntimeException("trongate.cloud could not set its values in config/: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Public URL of an app (trailing slash, as Trongate expects): its custom
     * domain once that is routed and its certificate issued, the {slug} host
     * until then. It must follow Manifests::secured_domains, never
     * verified_domain or the DNS gate alone, or every link the app generates
     * points at a host that does not serve it over HTTPS (yet).
     */
    static function base_url(array $app): string {
        $domain = Manifests::secured_domains($app)[0] ?? strtolower($app['slug'] . '.' . PLATFORM_APPS_DOMAIN);

        return 'https://' . $domain . '/';
    }

    /**
     * Single-quoted POSIX sh word (escapeshellarg differs on Windows)
     */
    static function sh_quote(string $value): string {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
