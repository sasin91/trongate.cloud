<?php

require_once __DIR__ . '/App_env.php';

/**
 * The environment variables an app's config/*.php reads, suggested while
 * the app is created: every getenv('NAME'), env('NAME') or other call to a
 * function whose name ends in "env" with a quoted name first (putenv
 * aside), and every $_ENV['NAME'].
 *
 * A literal default next to the read becomes the suggested value:
 * env('NAME', 'default'), getenv('NAME') ?: 'default' and
 * $_ENV['NAME'] ?? 'default'.
 *
 * Only reads PHP tokens (token_get_all), so nothing in the files runs.
 * Framework-free and without Composer packages: the panel image has none.
 */
final class App_env_suggestions {

    /** Same limits as the deploy's Config_editor */
    const MAX_FILES = 50;
    const MAX_FILE_BYTES = 256 * 1024;

    /** Function names ending in "env" that do not read a variable */
    const NOT_READS = ['putenv'];

    /**
     * @param array<string, string> $files file name in config/ => source
     * @return list<array{name: string, default: string, file: string}> in the order first read
     */
    static function from_files(array $files): array {
        $suggestions = [];

        foreach ($files as $file => $source) {
            foreach (self::reads((string) $source) as $read) {
                $suggestions = self::add($suggestions, $read['name'], $read['default'], (string) $file);
            }
        }

        return array_values($suggestions);
    }

    /**
     * The variable reads in one PHP source, in order
     *
     * @return list<array{name: string, default: string}>
     */
    static function reads(string $source): array {
        $tokens = self::code_tokens($source);
        $reads = [];

        foreach (array_keys($tokens) as $i) {
            $read = self::function_read($tokens, $i);

            if ($read === null) {
                $read = self::superglobal_read($tokens, $i);
            }

            if ($read !== null) {
                $reads[] = $read;
            }
        }

        return $reads;
    }

    /**
     * NAME(...)-style read starting at $i: a function ending in "env" whose
     * first argument is a quoted name
     *
     * @param list<array{0: int|string, 1: string}> $tokens
     * @return array{name: string, default: string}|null
     */
    private static function function_read(array $tokens, int $i): ?array {
        // \getenv is one token
        $function = ltrim(self::text($tokens, $i), '\\');

        if (!preg_match('/^\w*env$/i', $function)) {
            return null;
        }

        if (in_array(strtolower($function), self::NOT_READS, true)) {
            return null;
        }

        if (self::text($tokens, $i - 1) === 'function') {
            return null;
        }

        if (self::text($tokens, $i + 1) !== '(') {
            return null;
        }

        $name = self::quoted($tokens, $i + 2);

        if ($name === null) {
            return null;
        }

        // getenv('A' . $suffix) reads a name we cannot know
        if (!in_array(self::text($tokens, $i + 3), [',', ')'], true)) {
            return null;
        }

        $default = self::literal_argument($tokens, $i + 3);

        if ($default === null) {
            $default = self::literal_fallback($tokens, $i + 3);
        }

        return ['name' => $name, 'default' => $default ?? ''];
    }

    /**
     * $_ENV['NAME'] starting at $i
     *
     * @param list<array{0: int|string, 1: string}> $tokens
     * @return array{name: string, default: string}|null
     */
    private static function superglobal_read(array $tokens, int $i): ?array {
        if (self::text($tokens, $i) !== '$_ENV' || self::text($tokens, $i + 1) !== '[') {
            return null;
        }

        $name = self::quoted($tokens, $i + 2);

        if ($name === null || self::text($tokens, $i + 3) !== ']') {
            return null;
        }

        $default = null;

        if (self::text($tokens, $i + 4) === '??') {
            $default = self::literal($tokens, $i + 5);
        }

        return ['name' => $name, 'default' => $default ?? ''];
    }

    /**
     * The literal second argument when the call is NAME('X', literal)
     *
     * @param list<array{0: int|string, 1: string}> $tokens
     * @param int $i the token after the quoted name
     */
    private static function literal_argument(array $tokens, int $i): ?string {
        if (self::text($tokens, $i) !== ',') {
            return null;
        }

        $default = self::literal($tokens, $i + 1);

        if ($default === null || self::text($tokens, $i + 2) !== ')') {
            return null;
        }

        return $default;
    }

    /**
     * The literal after NAME('X') ?: or ??
     *
     * @param list<array{0: int|string, 1: string}> $tokens
     * @param int $i the token after the quoted name
     */
    private static function literal_fallback(array $tokens, int $i): ?string {
        if (self::text($tokens, $i) !== ')') {
            return null;
        }

        $operator = self::text($tokens, $i + 1);

        if ($operator === '??') {
            return self::literal($tokens, $i + 2);
        }

        // ?: is two tokens: ? and :
        if ($operator === '?' && self::text($tokens, $i + 2) === ':') {
            return self::literal($tokens, $i + 3);
        }

        return null;
    }

    /**
     * A quoted name that can be an environment variable
     *
     * @param list<array{0: int|string, 1: string}> $tokens
     */
    private static function quoted(array $tokens, int $i): ?string {
        if (($tokens[$i][0] ?? null) !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $name = self::unquote($tokens[$i][1]);

        if (App_env::name_error($name) !== null) {
            return null;
        }

        return $name;
    }

    /**
     * A quoted string, number, true or false as the text a developer would type
     *
     * @param list<array{0: int|string, 1: string}> $tokens
     */
    private static function literal(array $tokens, int $i): ?string {
        $kind = $tokens[$i][0] ?? null;

        if ($kind === T_CONSTANT_ENCAPSED_STRING) {
            return self::unquote($tokens[$i][1]);
        }

        if ($kind === T_LNUMBER || $kind === T_DNUMBER) {
            return $tokens[$i][1];
        }

        $word = strtolower(self::text($tokens, $i));

        if ($word === 'true' || $word === 'false') {
            return $word;
        }

        return null;
    }

    private static function unquote(string $quoted): string {
        $inner = substr($quoted, 1, -1);

        if ($quoted[0] === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        return stripcslashes($inner);
    }

    /**
     * The source's tokens without whitespace and comments, each as [kind, text]
     *
     * @return list<array{0: int|string, 1: string}>
     */
    private static function code_tokens(string $source): array {
        try {
            $all = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $e) {
            return [];
        }

        $tokens = [];

        foreach ($all as $token) {
            if (is_string($token)) {
                $tokens[] = [$token, $token];
                continue;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = [$token[0], $token[1]];
        }

        return $tokens;
    }

    /**
     * @param list<array{0: int|string, 1: string}> $tokens
     */
    private static function text(array $tokens, int $i): string {
        return $tokens[$i][1] ?? '';
    }

    /**
     * Add a read unless the platform sets that variable; a later default
     * fills one the first read left out
     *
     * @param array<string, array{name: string, default: string, file: string}> $suggestions
     * @return array<string, array{name: string, default: string, file: string}>
     */
    private static function add(array $suggestions, string $name, string $default, string $file): array {
        if (isset($suggestions[$name])) {
            if ($suggestions[$name]['default'] === '') {
                $suggestions[$name]['default'] = $default;
            }

            return $suggestions;
        }

        $suggestions[$name] = ['name' => $name, 'default' => $default, 'file' => $file];

        return $suggestions;
    }
}
