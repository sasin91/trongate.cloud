<?php

/**
 * An app's own environment variables, in two kinds stored encrypted in the
 * secrets table under module 'app' and the app id:
 * - secrets (service 'env', e.g. API keys): write-only, never shown back
 * - variables (service 'env_plain', e.g. APP_ENV): shown and edited in the panel
 * A name is one kind or the other. Both are written to the app's p{id}-env
 * Secret on each deploy (Manifests::app, loaded with envFrom).
 *
 * Framework-free: the apps module validates with it, the worker reads with it.
 */
final class App_env {

    const MODULE = 'app';
    const SERVICE = 'env';
    const PLAIN_SERVICE = 'env_plain';

    const MAX_VARIABLES = 100;
    const MAX_NAME_LENGTH = 100;
    const MAX_VALUE_BYTES = 32768;

    /** Set by the platform on the app container (Manifests::_deployment); they would be ignored */
    const RESERVED = ['PORT', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME'];

    /**
     * The worker's Module_reference for the app's variables
     */
    static function reference(int $app_id, string $service = self::SERVICE): string {
        return 'secrets:' . self::MODULE . ',' . $app_id . ',' . $service;
    }

    /**
     * NAME=value lines, as in a .env file: blank lines and # comments are
     * skipped, `export ` is dropped, and quotes around a whole value come off.
     *
     * @return array<string, string>
     * @throws InvalidArgumentException naming the first line that cannot be used
     */
    static function parse_lines(string $text): array {
        $env = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, null);
            $name = trim((string) $name);
            $error = $value === null ? "Write {$name} as {$name}=value." : self::name_error($name);

            if ($error !== null) {
                throw new InvalidArgumentException($error);
            }

            $value = self::unquote(trim($value));

            if (strlen($value) > self::MAX_VALUE_BYTES) {
                throw new InvalidArgumentException("The value of {$name} is too long.");
            }

            $env[$name] = $value;
        }

        return $env;
    }

    /**
     * The variables as NAME=value lines, the way parse_lines() reads them back
     *
     * @param array<string, string> $env
     */
    static function to_lines(array $env): string {
        $lines = [];

        foreach ($env as $name => $value) {
            $needs_quotes = $value !== trim($value) || self::unquote($value) !== $value;
            $lines[] = $name . '=' . ($needs_quotes ? '"' . $value . '"' : $value);
        }

        return implode("\n", $lines);
    }

    /**
     * A value without the matching quotes around it, if it has them
     */
    private static function unquote(string $value): string {
        $is_quoted = strlen($value) >= 2
            && in_array($value[0], ['"', "'"], true)
            && $value[-1] === $value[0];

        return $is_quoted ? substr($value, 1, -1) : $value;
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
