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
     * POSIX sh that sets the given constants in a PHP file with Config_editor
     * (parse, change the values, regenerate; appends a constant the file does
     * not define), then lints the result with the image's own PHP. Any failure
     * exits non-zero with a message saying why, before the file is replaced,
     * and the pod's init container fails instead of serving a broken config.
     *
     * @param array<string, string> $defines e.g. ['BASE_URL' => 'https://x/', 'ENV' => 'prod']
     */
    static function patch_script(array $defines, string $file = 'config/config.php', string $label = 'config/config.php'): string {
        self::assert_defines($defines);

        $editor = preg_replace('/^<\?php\s*/', '', (string) file_get_contents(__DIR__ . '/Config_editor.php'));
        $code = $editor . "\n"
            . '[, $f, $label] = $argv;' . "\n"
            . 'try {' . "\n"
            . '    $out = Config_editor::set_constants((string) file_get_contents($f), ' . var_export($defines, true) . ');' . "\n"
            . '} catch (Throwable $e) {' . "\n"
            . '    fwrite(STDERR, "trongate.cloud could not update {$label}: {$e->getMessage()}\\n");' . "\n"
            . '    exit(1);' . "\n"
            . '}' . "\n"
            . 'if (file_put_contents("{$f}.new", $out) === false) { fwrite(STDERR, "Cannot write {$f}.new\\n"); exit(1); }' . "\n";

        return implode("\n", [
            'set -eu',
            'f=' . self::sh_quote($file),
            'label=' . self::sh_quote($label),
            // FrankenPHP images without a php binary still carry the CLI.
            'if command -v php >/dev/null 2>&1; then php() { command php "$@"; }; else php() { frankenphp php-cli "$@"; }; fi',
            'php -r ' . self::sh_quote($code) . ' -- "$f" "$label"',
            'if ! lint=$(php -l "$f.new" 2>&1); then echo "trongate.cloud could not update $label: $lint" >&2; exit 1; fi',
            'mv "$f.new" "$f"',
        ]) . "\n";
    }

    /**
     * Set constants in PHP source in-process; the twin of patch_script. The
     * deploy task runs it on the checked-out config before building, so a
     * config the editor cannot handle fails the deploy with the reason.
     *
     * @param array<string, string> $defines
     * @throws RuntimeException when the source is not valid PHP or cannot be edited
     */
    static function patch_php(string $source, array $defines, string $label = 'config/config.php'): string {
        self::assert_defines($defines);
        require_once __DIR__ . '/Config_editor.php';

        try {
            return Config_editor::set_constants($source, $defines);
        } catch (RuntimeException $e) {
            throw new RuntimeException("trongate.cloud could not update {$label}: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * The constants the platform sets in an app's config/config.php
     *
     * @return array<string, string>
     */
    static function config_defines(array $app): array {
        return ['BASE_URL' => self::base_url($app), 'ENV' => self::env($app)];
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
