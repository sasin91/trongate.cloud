<?php

require_once __DIR__ . '/../../../config/platform.php';

/**
 * Renders the Trongate config files we mount over a customer image.
 * Customer apps hardcode config and don't read env, so we patch/replace the files.
 *
 * Framework-free: loaded by the worker.
 */
class Trongate_config {

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
     * POSIX sh that rewrites define('NAME', ...) lines in a PHP file to the given values,
     * appending the define when the file has none.
     *
     * @param array<string, string> $defines e.g. ['BASE_URL' => 'https://x/', 'ENV' => 'prod']
     */
    static function patch_script(array $defines, string $file = 'config/config.php'): string {
        $lines = ['set -eu', 'f=' . self::sh_quote($file)];

        foreach ($defines as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw new InvalidArgumentException("Invalid constant name: {$name}");
            }

            if (preg_match('/[\r\n\0]/', $value)) {
                throw new InvalidArgumentException("Value for {$name} must be a single line");
            }

            $define = 'define(' . self::php_string($name) . ', ' . self::php_string($value) . ');';
            $pattern = '^[[:space:]]*define\([[:space:]]*[\'"]' . $name . '[\'"]';
            $sed = 's|' . $pattern . '.*$|' . self::sed_replacement($define) . '|';

            $lines[] = 'if grep -Eq ' . self::sh_quote($pattern) . ' "$f"; then';
            $lines[] = '    sed -i -E ' . self::sh_quote($sed) . ' "$f"';
            $lines[] = 'else';
            $lines[] = '    printf \'%s\n\' ' . self::sh_quote($define) . ' >> "$f"';
            $lines[] = 'fi';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Public URL of a app (trailing slash, as Trongate expects): its custom
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

    /**
     * Escape a literal for the replacement side of sed s|..|..|
     */
    static function sed_replacement(string $value): string {
        return str_replace(['\\', '&', '|'], ['\\\\', '\\&', '\\|'], $value);
    }
}
