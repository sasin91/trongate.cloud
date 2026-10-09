<?php

/**
 * One value the platform sets in an app's config/*.php files (Config_editor).
 *
 * $key is either a constant name, matching define('NAME', ...) and
 * `const NAME = ...;`, or an array element written as PHP, matching it in
 * any assignment to that variable or a part of it:
 *
 *     new Config_rule('ENV', 'prod')
 *     new Config_rule("\$databases['default']['host']", 'db', 'database.php')
 *
 * $file limits the rule to one file in config/; without it the rule applies
 * to every file. A key found nowhere in scope is appended to $file
 * (config.php when unscoped), which is created when the app has none.
 */
final readonly class Config_rule {

    /** Constant name, or $variable with one or more ['key'] */
    const KEY_PATTERN = '/^(?:[A-Za-z_][A-Za-z0-9_]*|\$[A-Za-z_][A-Za-z0-9_]*(?:\[\'[^\'\\\\]*\'\])+)$/';

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

        if ($key[0] === '$') {
            preg_match_all("/\\['([^']*)'\\]/", $key, $keys);
            $this->constant = null;
            $this->variable = substr($key, 1, (int) strpos($key, '[') - 1);
            $this->path = $keys[1];
        } else {
            $this->constant = $key;
            $this->variable = null;
            $this->path = [];
        }
    }

    function applies_to(string $file): bool {
        return $this->file === null || $this->file === $file;
    }

    /** The file a key found nowhere is appended to */
    function target_file(): string {
        return $this->file ?? 'config.php';
    }
}
