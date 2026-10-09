<?php

/**
 * One value the platform sets in an app's config/*.php files (Config_editor).
 *
 * $key without a dot is a constant, matching define('NAME', ...) and
 * `const NAME = ...;`. With dots it is a variable followed by array keys,
 * matched in any assignment to the variable or a part of it:
 *
 *     new Config_rule('ENV', 'prod')
 *     new Config_rule('databases.default.host', 'db', 'database.php')
 *     new Config_rule('config.trongate_email.smtp_host', 'mail.example.com')
 *
 * $file limits the rule to one file in config/; without it the rule applies
 * to every file. A key found nowhere in scope is appended to $file
 * (config.php when unscoped), which is created when the app has none.
 */
final readonly class Config_rule {

    /** NAME, or variable.key[.key...] (keys are anything but dots) */
    const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[^.]+)*$/';

    const FILE_PATTERN = '/^[A-Za-z0-9_-]+\.php$/';

    /** Constant name, or null for an array element */
    public ?string $constant;

    /** Variable name (without $) of an array element */
    public ?string $variable;

    /** @var list<string> keys of an array element, outermost first */
    public array $path;

    function __construct(
        public string $key,
        public string|int|float|bool|null $value,
        public ?string $file = null,
    ) {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new InvalidArgumentException("Invalid config key: {$key}");
        }

        if ($file !== null && !preg_match(self::FILE_PATTERN, $file)) {
            throw new InvalidArgumentException("Invalid config file: {$file}");
        }

        $segments = explode('.', $key);
        $this->constant = count($segments) === 1 ? $key : null;
        $this->variable = count($segments) > 1 ? array_shift($segments) : null;
        $this->path = $this->variable !== null ? $segments : [];
    }

    function applies_to(string $file): bool {
        return $this->file === null || $this->file === $file;
    }

    /** The file a key found nowhere is appended to */
    function target_file(): string {
        return $this->file ?? 'config.php';
    }
}
