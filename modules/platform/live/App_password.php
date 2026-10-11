<?php

/**
 * Password hashes for the Database tab's "Change password": which columns
 * hold them, what the hash in a cell was made with, and a new hash made the
 * same way, with PHP's password_hash() (what Trongate's login verifies with
 * password_verify()). The plain password is never stored or logged.
 *
 * Hashing runs in the panel's own web pod, so a hash whose settings would
 * take seconds or hundreds of MB there is not matched (MAX_* below); the
 * customer can still pick bcrypt or Argon2id with PHP's defaults.
 */
final class App_password {
    /** bcrypt cost: each step doubles the time; 14 takes about a second here */
    const MAX_BCRYPT_COST = 14;

    /** Argon2 memory_cost in KiB (128 MiB); PHP's default is 64 MiB */
    const MAX_ARGON2_MEMORY = 131072;

    const MAX_ARGON2_TIME = 10;

    const MAX_ARGON2_THREADS = 4;

    /** bcrypt reads only the first 72 bytes; refusing longer avoids a hash that ignores the rest */
    const BCRYPT_MAX_BYTES = 72;

    const TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

    /** Column names that hold passwords: password, passwd, pwd, pass, *_password, password_hash, password_digest */
    const NAME_PATTERN = '/(^|_)(pass(word)?|passwd|pwd)(_?(hash(ed)?|digest))?$/i';

    /**
     * Whether a column can take a password hash and is named like it holds one
     *
     * @param array{name: string, type: string, editable: bool} $column
     */
    static function is_password_column(array $column): bool {
        return self::can_hold_hash($column) && preg_match(self::NAME_PATTERN, $column['name']) === 1;
    }

    /**
     * Whether a column is text the row form can write (a hash can go in it)
     *
     * @param array{type: string, editable: bool} $column
     */
    static function can_hold_hash(array $column): bool {
        return $column['editable'] && in_array($column['type'], self::TEXT_TYPES, true);
    }

    /**
     * Whether a value is a hash password_verify() understands
     */
    static function is_hash(?string $value): bool {
        return self::settings($value) !== null;
    }

    /**
     * What a value was hashed with: algorithm (bcrypt, argon2i, argon2id) and its
     * options, or null when it is not a password_hash() hash (empty, plain text,
     * md5, sha1, ...)
     *
     * @return array{algorithm: string, options: array<string, int>, prefix: string}|null
     */
    static function settings(?string $value): ?array {
        if ($value === null) {
            return null;
        }

        // $2a$ and $2b$ come from other bcrypt libraries; password_get_info() only names $2y$
        if (preg_match('/^\$(2[aby])\$(\d\d)\$[.\/A-Za-z0-9]{53}$/', $value, $m) === 1) {
            return ['algorithm' => 'bcrypt', 'options' => ['cost' => (int) $m[2]], 'prefix' => '$' . $m[1] . '$'];
        }

        if (preg_match('/^\$(argon2id?)\$v=19\$m=(\d+),t=(\d+),p=(\d+)\$[A-Za-z0-9+\/]+\$[A-Za-z0-9+\/]+$/', $value, $m) === 1) {
            return [
                'algorithm' => $m[1],
                'options' => ['memory_cost' => (int) $m[2], 'time_cost' => (int) $m[3], 'threads' => (int) $m[4]],
                'prefix' => '$' . $m[1] . '$',
            ];
        }

        return null;
    }

    /**
     * The ways a new hash can be made for a cell holding $current, keyed by
     * what the form posts: 'same' (only when the current hash is recognised,
     * available here and within the MAX_* limits), 'bcrypt' and 'argon2id'
     * (PHP's defaults; argon2id when this PHP has it and its 97 characters fit
     * $column_type). 'default' is the one to preselect; 'note' says why 'same' is missing.
     *
     * @return array{choices: array<string, string>, default: string, current: string, note: string|null}
     */
    static function choices(?string $current, string $column_type = 'text'): array {
        $settings = self::settings($current);
        $choices = [];
        $note = null;

        if ($settings !== null) {
            $refusal = self::refusal($settings);

            if ($refusal === null) {
                $choices['same'] = 'Same as now: ' . self::describe($settings);
            } else {
                $note = $refusal;
            }
        } elseif ($current !== null && $current !== '') {
            $note = 'The current value is not a password_hash() hash, so check that your app verifies bcrypt or Argon2id before saving.';
        }

        $choices['bcrypt'] = 'bcrypt, cost ' . self::default_bcrypt_cost() . ' (PHP default)';

        if (defined('PASSWORD_ARGON2ID') && self::too_long(str_repeat('x', 97), $column_type) === null) {
            $choices['argon2id'] = 'Argon2id, PHP defaults';
        }

        return [
            'choices' => $choices,
            'default' => array_key_first($choices),
            'current' => self::describe_value($current),
            'note' => $note,
        ];
    }

    /**
     * A new hash of $password made the $choice way (a key of choices())
     *
     * @throws RuntimeException when $choice is not offered for $current, or the password cannot be hashed that way
     */
    static function hash(#[\SensitiveParameter] string $password, ?string $current, string $choice): string {
        $offered = self::choices($current)['choices'];

        if (!array_key_exists($choice, $offered)) {
            throw new RuntimeException('That hashing choice is not available for this value.');
        }

        if (str_contains($password, "\0")) {
            throw new RuntimeException('The password cannot contain a NUL byte.');
        }

        $settings = self::settings_for_choice($current, $choice);

        if ($settings['algorithm'] === 'bcrypt' && strlen($password) > self::BCRYPT_MAX_BYTES) {
            throw new RuntimeException('bcrypt only reads the first ' . self::BCRYPT_MAX_BYTES . ' bytes of a password. Use a shorter one, or Argon2id.');
        }

        try {
            $hash = password_hash($password, self::php_algorithm($settings['algorithm']), $settings['options']);
        } catch (ValueError $e) {
            throw new RuntimeException('Could not hash it that way here: ' . $e->getMessage() . ' Pick another choice.');
        }

        // $2b$ is the same algorithm as PHP's $2y$; keep the prefix the app already stores
        if ($settings['prefix'] === '$2b$') {
            $hash = '$2b$' . substr($hash, 4);
        }

        return $hash;
    }

    /**
     * The algorithm, options and prefix a new hash is made with, for a $choice that choices() offered
     *
     * @return array{algorithm: string, options: array<string, int>, prefix: string}
     */
    static function settings_for_choice(?string $current, string $choice): array {
        if ($choice === 'argon2id') {
            return ['algorithm' => 'argon2id', 'options' => [], 'prefix' => '$argon2id$'];
        }

        if ($choice === 'same') {
            $settings = self::settings($current);

            if ($settings !== null) {
                return $settings;
            }
        }

        return ['algorithm' => 'bcrypt', 'options' => [], 'prefix' => '$2y$'];
    }

    /**
     * password_hash()'s constant for an algorithm name from settings()
     */
    static function php_algorithm(string $algorithm): string {
        if ($algorithm === 'argon2i') {
            return PASSWORD_ARGON2I;
        }

        if ($algorithm === 'argon2id') {
            return PASSWORD_ARGON2ID;
        }

        return PASSWORD_BCRYPT;
    }

    /**
     * Why a hash in a column of $column_type would not fit, or null when it does
     */
    static function too_long(string $hash, string $column_type): ?string {
        if (preg_match('/^(var)?char\((\d+)\)/i', $column_type, $m) !== 1 || strlen($hash) <= (int) $m[2]) {
            return null;
        }

        return 'The new hash is ' . strlen($hash) . ' characters, but the column holds ' . $m[2] . '. Pick bcrypt (60 characters) or widen the column with SQL.';
    }

    /**
     * Why the panel will not match these settings, or null when it will
     *
     * @param array{algorithm: string, options: array<string, int>} $settings
     */
    static function refusal(array $settings): ?string {
        $algorithm = $settings['algorithm'];

        if ($algorithm !== 'bcrypt' && !defined('PASSWORD_' . strtoupper($algorithm))) {
            return 'This server cannot make ' . $algorithm . ' hashes, so pick another algorithm.';
        }

        if (!self::too_slow($settings)) {
            return null;
        }

        return 'The current hash (' . self::describe($settings) . ') is too slow to make here, so pick another algorithm, or set a hash made in your app with SQL.';
    }

    /**
     * Whether making a hash with these settings would take too long or too much memory in the panel's pod
     *
     * @param array{algorithm: string, options: array<string, int>} $settings
     */
    static function too_slow(array $settings): bool {
        $options = $settings['options'];

        if ($settings['algorithm'] === 'bcrypt') {
            return $options['cost'] > self::MAX_BCRYPT_COST;
        }

        if ($options['memory_cost'] > self::MAX_ARGON2_MEMORY) {
            return true;
        }

        if ($options['time_cost'] > self::MAX_ARGON2_TIME) {
            return true;
        }

        return $options['threads'] > self::MAX_ARGON2_THREADS;
    }

    /**
     * @param array{algorithm: string, options: array<string, int>} $settings
     */
    static function describe(array $settings): string {
        $options = $settings['options'];

        if ($settings['algorithm'] === 'bcrypt') {
            return 'bcrypt, cost ' . $options['cost'];
        }

        $threads = $options['threads'] === 1 ? '1 thread' : $options['threads'] . ' threads';

        return ucfirst($settings['algorithm']) . ', ' . intdiv($options['memory_cost'], 1024) . ' MiB, ' . $options['time_cost'] . ' passes, ' . $threads;
    }

    /**
     * What a cell holds, for the form: its hash settings, 'empty' or 'not a password hash'
     */
    static function describe_value(?string $value): string {
        if ($value === null || $value === '') {
            return 'empty';
        }

        $settings = self::settings($value);

        if ($settings === null) {
            return 'not a password hash';
        }

        return self::describe($settings);
    }

    static function default_bcrypt_cost(): int {
        return PASSWORD_BCRYPT_DEFAULT_COST;
    }
}
