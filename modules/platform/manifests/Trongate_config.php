<?php

require_once __DIR__ . '/../../../config/platform.php';

/**
 * Renders the Trongate config files we mount over a customer image.
 * Customer apps hardcode config and don't read env, so we patch/replace the files.
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
     * Full config/database.php
     *
     * $db keys: host, port, user, password, database.
     * v2 apps read $databases['default']; v1 apps read HOST/PORT/USER/PASSWORD/DATABASE constants.
     */
    static function database_php(array $db, string $type = 'trongate_v2'): string {
        $values = [
            'host' => (string) ($db['host'] ?? '127.0.0.1'),
            'port' => (string) ($db['port'] ?? '3306'),
            'user' => (string) ($db['user'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
            'database' => (string) ($db['database'] ?? ''),
        ];

        $php = "<?php\n// Managed by trongate.cloud - changes are overwritten on deploy\n";

        if ($type === 'trongate_v1') {
            foreach ($values as $key => $value) {
                $php .= 'define(' . self::php_string(strtoupper($key)) . ', ' . self::php_string($value) . ");\n";
            }

            return $php;
        }

        $php .= "\$databases = [\n    'default' => [\n";

        foreach ($values as $key => $value) {
            $php .= '        ' . self::php_string($key) . ' => ' . self::php_string($value) . ",\n";
        }

        return $php . "    ],\n];\n";
    }

    /**
     * POSIX sh that rewrites every define('NAME', ...) in a PHP file to the given
     * values, appending the define when the file has none, then lints the result.
     *
     * The rewrite runs config_patcher.php with the image's own PHP and works on
     * tokens, so multi-line defines (a closure, a getenv() ternary) are replaced
     * whole instead of leaving their tail behind as a parse error.
     *
     * @param array<string, string> $defines e.g. ['BASE_URL' => 'https://x/', 'ENV' => 'prod']
     */
    static function patch_script(array $defines, string $file = 'config/config.php'): string {
        self::assert_defines($defines);

        $patcher = preg_replace('/^<\?php\s*/', '', (string) file_get_contents(__DIR__ . '/config_patcher.php'));
        $code = $patcher
            . "\n\$f = \$argv[1];\n"
            . '$out = tgc_patch_defines((string) file_get_contents($f), ' . var_export($defines, true) . ");\n"
            . "if (file_put_contents(\$f, \$out) === false) { fwrite(STDERR, \"Cannot write {\$f}\\n\"); exit(1); }\n";

        return implode("\n", [
            'set -eu',
            'f=' . self::sh_quote($file),
            // FrankenPHP images without a php binary still carry the CLI.
            'if command -v php >/dev/null 2>&1; then php() { command php "$@"; }; else php() { frankenphp php-cli "$@"; }; fi',
            'php -r ' . self::sh_quote($code) . ' -- "$f"',
            'php -l "$f" >/dev/null',
        ]) . "\n";
    }

    /**
     * Rewrite define('NAME', ...) calls in PHP source; the in-process twin of patch_script.
     *
     * @param array<string, string> $defines
     */
    static function patch_php(string $source, array $defines): string {
        self::assert_defines($defines);
        require_once __DIR__ . '/config_patcher.php';

        return tgc_patch_defines($source, $defines);
    }

    /**
     * @param array<string, string> $defines
     */
    private static function assert_defines(array $defines): void {
        foreach ($defines as $name => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name)) {
                throw new InvalidArgumentException("Invalid constant name: {$name}");
            }

            if (preg_match('/[\r\n\0]/', (string) $value)) {
                throw new InvalidArgumentException("Value for {$name} must be a single line");
            }
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
     * Single-quoted PHP string literal
     */
    static function php_string(string $value): string {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Single-quoted POSIX sh word (escapeshellarg differs on Windows)
     */
    static function sh_quote(string $value): string {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
