<?php

require_once __DIR__ . '/Runtime_settings.php';
require_once __DIR__ . '/../../../config/platform.php';

/**
 * Renders the Trongate config files we mount over a customer image.
 * Customer apps hardcode config and don't read env, so we edit/replace the files.
 *
 * Framework-free: loaded by the worker.
 */
class Trongate_config {

    /**
     * Bump whenever what the platform writes into an app's config changes
     * (rules(), Mail_capture::rules): every apply records it on the
     * deployment, and redeploy_outdated_apps redeploys the apps whose
     * current deployment is behind, so they pick it up without a push.
     * 2: mail capture (trongate_email.php).
     * 3: DEFAULT_MODULE 'setup' becomes 'welcome' (default_module_rules).
     */
    const CONFIG_VERSION = 3;

    /**
     * What the platform sets in an app's config/*.php: BASE_URL and ENV
     * wherever the app defines them, and the database connection in
     * database.php (v2 apps read databases.default.*, v1 apps the
     * HOST/PORT/USER/PASSWORD/DATABASE constants), plus the mail inbox in
     * trongate_email.php while mail capture is on (Mail_capture::rules),
     * and DEFAULT_MODULE 'welcome' in place of 'setup' (default_module_rules).
     *
     * @param array{host: string, port: string, user: string, password: string, database: string} $db
     * @param array<string, string> $files the app's config/*.php: file name => source
     * @return list<Config_rule>
     */
    static function rules(array $app, array $db, array $files = []): array {
        require_once __DIR__ . '/Config_rule.php';
        require_once __DIR__ . '/../mail/Mail_capture.php';

        $rules = [
            new Config_rule('BASE_URL', self::base_url($app)),
            new Config_rule('ENV', Runtime_settings::trongate_env($app)),
        ];
        $v1 = ($app['type'] ?? 'trongate_v2') === 'trongate_v1';

        foreach (['host', 'port', 'user', 'password', 'database'] as $key) {
            $rules[] = new Config_rule($v1 ? strtoupper($key) : "databases.default.{$key}", (string) $db[$key], 'database.php');
        }

        return [...$rules, ...self::default_module_rules($files), ...Mail_capture::rules($app, $files)];
    }

    /**
     * A fresh trongate-framework app ships with DEFAULT_MODULE 'setup', a
     * walkthrough that writes database.php and config.php. The platform
     * sets those (and the wizard creates Trongate's tables and first
     * administrator), so the app opens on 'welcome', as setup would leave
     * it. Any other default module is the app's choice and is left alone.
     *
     * @param array<string, string> $files the app's config/*.php: file name => source
     * @return list<Config_rule>
     * @throws Config_error when a file is not valid PHP
     */
    static function default_module_rules(array $files): array {
        require_once __DIR__ . '/Config_editor.php';

        foreach ($files as $file => $source) {
            $settings = Config_editor::settings($file, $source);
            $default_module = $settings['DEFAULT_MODULE'] ?? null;

            if ($default_module !== null && $default_module['literal'] && $default_module['value'] === 'setup') {
                return [new Config_rule('DEFAULT_MODULE', 'welcome', $file)];
            }
        }

        return [];
    }

    /**
     * The app's config files (file name in config/ => source) with the
     * platform's values set (rules()); only the edited files are returned,
     * and mounted over the image's.
     *
     * @return array<string, string>
     * @throws Config_error when a file is not valid PHP or cannot be edited
     */
    static function config_files(array $files, array $app, array $db): array {
        require_once __DIR__ . '/Config_editor.php';

        return Config_editor::apply($files, self::rules($app, $db, $files));
    }

    /**
     * Public URL of an app (trailing slash, as Trongate expects): its custom
     * domain once that is routed and its certificate issued, its first
     * hostname until then (the {slug} host, or the domain of a production app
     * whose staging app took that). It must follow Manifests::secured_domains, never
     * verified_domain or the DNS gate alone, or every link the app generates
     * points at a host that does not serve it over HTTPS (yet).
     */
    static function base_url(array $app): string {
        $domain = Manifests::secured_domains($app)[0] ?? Manifests::hostnames($app)[0] ?? strtolower($app['slug'] . '.' . PLATFORM_APPS_DOMAIN);

        return 'https://' . $domain . '/';
    }

    /**
     * Single-quoted POSIX sh word (escapeshellarg differs on Windows)
     */
    static function sh_quote(string $value): string {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
