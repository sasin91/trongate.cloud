<?php

/**
 * Sets global constants in a customer's PHP config file: parses the file,
 * changes the value of every define('NAME', ...) call and `const NAME = ...;`
 * for the given names, and regenerates the source with everything else
 * (formatting, comments, other code) left byte for byte as it was.
 *
 * The file is lexed and parsed with PHP's own parser (PhpToken, TOKEN_PARSE),
 * so a file that is not valid PHP is refused before anything is changed, and
 * the output is parsed again and checked to define exactly the requested
 * values before it is returned. What it handles that text patching does not:
 *  - values over several lines (closures, getenv() ternaries, arrays)
 *  - comments and strings that mention define('NAME') or the value
 *  - single or double quoted names, \define, DEFINE, named arguments
 *  - constants defined in if/else branches (every definition is changed)
 *  - `const NAME = ...;` (class constants are left alone)
 *  - constants the file does not define at all (appended)
 *
 * Standalone and dependency-free: Trongate_config::patch_script embeds this
 * file's body in `php -r` and runs it inside the customer image, and the
 * deploy task runs it in the worker first to refuse a config it cannot edit.
 */
final class Config_editor {

    /**
     * @param array<string, string> $values constant name => value (written as a string literal)
     * @throws RuntimeException when the source is not valid PHP or cannot be edited safely
     */
    static function set_constants(string $source, array $values): string {
        $tokens = self::parse($source, 'The file is not valid PHP');
        $spans = self::value_spans($tokens, array_keys($values));

        $out = '';
        $i = 0;

        foreach ($spans as [$start, $end, $name]) {
            for (; $i < $start; $i++) {
                $out .= $tokens[$i]->text;
            }

            $out .= self::literal($values[$name]);
            $i = $end + 1;
        }

        for ($count = count($tokens); $i < $count; $i++) {
            $out .= $tokens[$i]->text;
        }

        $found = array_column($spans, 2, 2);
        $missing = '';

        foreach ($values as $name => $value) {
            if (!isset($found[$name])) {
                $missing .= 'define(' . self::literal((string) $name) . ', ' . self::literal($value) . ");\n";
            }
        }

        if ($missing !== '') {
            // After a closing tag, appended code would be output; reopen PHP instead
            $last = end($tokens);
            $in_php = $last !== false && !$last->is([T_CLOSE_TAG, T_INLINE_HTML]);
            $out = rtrim($out, "\n") . "\n" . ($in_php ? '' : "<?php\n") . $missing;
        }

        self::verify($out, $values);

        return $out;
    }

    /**
     * Fail unless $source parses and every definition of each name is exactly its value
     *
     * @param array<string, string> $values
     */
    static function verify(string $source, array $values): void {
        $tokens = self::parse($source, 'The regenerated file is not valid PHP');
        $defined = [];

        foreach (self::value_spans($tokens, array_keys($values)) as [$start, $end, $name]) {
            $text = '';

            for ($i = $start; $i <= $end; $i++) {
                $text .= $tokens[$i]->text;
            }

            if ($text !== self::literal($values[$name])) {
                throw new RuntimeException("The regenerated file sets {$name} to {$text}, not the expected value");
            }

            $defined[$name] = true;
        }

        foreach ($values as $name => $value) {
            if (!isset($defined[$name])) {
                throw new RuntimeException("The regenerated file does not define {$name}");
            }
        }
    }

    /**
     * Tokens of a whole file, parsed: a syntax error throws instead of lexing on
     *
     * @return list<PhpToken>
     */
    static function parse(string $source, string $error): array {
        try {
            return PhpToken::tokenize($source, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new RuntimeException("{$error} (line {$e->getLine()}: {$e->getMessage()})", 0, $e);
        }
    }

    /**
     * Token span of the value of each definition of the given constants, in
     * source order: [first token, last token, name]
     *
     * @param list<PhpToken> $tokens
     * @param list<string|int> $names
     * @return list<array{0: int, 1: int, 2: string}>
     */
    static function value_spans(array $tokens, array $names): array {
        $wanted = array_fill_keys(array_map('strval', $names), true);
        $count = count($tokens);
        $spans = [];

        // Brace stack: true for a class-like body, where `const` is a class constant
        $braces = [];
        $class_body_next = false;
        $prev = null;

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];

            if ($t->isIgnorable()) {
                continue;
            }

            if ($t->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && !($prev !== null && $prev->is(T_DOUBLE_COLON))) {
                $class_body_next = true;
            } elseif ($t->text === '{' || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $braces[] = $class_body_next;
                $class_body_next = false;
            } elseif ($t->text === '}') {
                array_pop($braces);
            } elseif ($t->is(T_CONST) && !in_array(true, $braces, true) && !($prev !== null && $prev->is(T_USE))) {
                $i = self::const_statement($tokens, $i, $wanted, $spans);
                $prev = $tokens[$i];

                continue;
            } elseif (self::is_define_call($t, $prev)) {
                self::define_call($tokens, $i, $wanted, $spans);
            }

            $prev = $t;
        }

        // A definition nested inside another one's value is replaced with it
        usort($spans, fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $kept = [];
        $end = -1;

        foreach ($spans as $span) {
            if ($span[0] > $end) {
                $kept[] = $span;
                $end = $span[1];
            }
        }

        return $kept;
    }

    /**
     * Single-quoted PHP string literal
     */
    static function literal(string $value): string {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private static function is_define_call(PhpToken $t, ?PhpToken $prev): bool {
        return $t->is([T_STRING, T_NAME_FULLY_QUALIFIED])
            && strcasecmp(ltrim($t->text, '\\'), 'define') === 0
            && !($prev !== null && $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST]));
    }

    /**
     * define(NAME, VALUE): record VALUE's span when NAME is a wanted literal
     *
     * @param list<PhpToken> $tokens
     * @param array<string, true> $wanted
     * @param list<array{0: int, 1: int, 2: string}> $spans
     */
    private static function define_call(array $tokens, int $i, array $wanted, array &$spans): void {
        $open = self::next($tokens, $i);

        if (($tokens[$open]->text ?? null) !== '(') {
            return;
        }

        [$args] = self::arguments($tokens, $open + 1, [')']);
        $name_arg = null;
        $value_arg = null;
        $position = 0;

        foreach ($args as [$start, $end]) {
            $colon = self::next($tokens, $start);

            // Named argument: identifier ':' (never '::', which is one token)
            if ($start < $end && $tokens[$start]->is(T_STRING) && $tokens[$colon]->text === ':') {
                $span = [self::next($tokens, $colon), $end];

                match (strtolower($tokens[$start]->text)) {
                    'constant_name' => $name_arg = $span,
                    'value' => $value_arg = $span,
                    default => null,
                };

                continue;
            }

            if ($position === 0) {
                $name_arg = [$start, $end];
            } elseif ($position === 1) {
                $value_arg = [$start, $end];
            }

            $position++;
        }

        if ($name_arg === null || $name_arg[0] !== $name_arg[1]) {
            return;
        }

        $name = self::string_value($tokens[$name_arg[0]]);

        if ($name === null || !isset($wanted[$name])) {
            return;
        }

        if ($value_arg === null) {
            throw new RuntimeException("define('{$name}') on line {$tokens[$i]->line} has no value");
        }

        $spans[] = [$value_arg[0], $value_arg[1], $name];
    }

    /**
     * const A = x, B = y; record the spans of wanted names' values
     *
     * @param list<PhpToken> $tokens
     * @param array<string, true> $wanted
     * @param list<array{0: int, 1: int, 2: string}> $spans
     * @return int index of the statement's ';'
     */
    private static function const_statement(array $tokens, int $i, array $wanted, array &$spans): int {
        [$declarations, $end] = self::arguments($tokens, $i + 1, [';', T_CLOSE_TAG]);

        foreach ($declarations as [$start, $last]) {
            $equals = self::next($tokens, $start);

            if (!$tokens[$start]->is(T_STRING) || ($tokens[$equals]->text ?? null) !== '=') {
                continue;
            }

            if (isset($wanted[$tokens[$start]->text])) {
                $spans[] = [self::next($tokens, $equals), $last, $tokens[$start]->text];
            }
        }

        return $end;
    }

    /**
     * Split a comma-separated list at depth 0, starting after an opening
     * bracket (or a keyword) and ending at one of $close
     *
     * @param list<PhpToken> $tokens
     * @param list<string|int> $close
     * @return array{0: list<array{0: int, 1: int}>, 1: int} [first and last significant token of each item, index of the closing token]
     */
    private static function arguments(array $tokens, int $i, array $close): array {
        $items = [];
        $depth = 0;
        $start = null;
        $last = null;
        $count = count($tokens);

        for (; $i < $count; $i++) {
            $t = $tokens[$i];

            if ($t->isIgnorable()) {
                continue;
            }

            if ($depth === 0 && ($t->is($close) || $t->text === ',')) {
                if ($start !== null) {
                    $items[] = [$start, $last];
                }

                if ($t->text !== ',') {
                    return [$items, $i];
                }

                $start = null;

                continue;
            }

            if (in_array($t->text, ['(', '[', '{'], true) || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
                $depth++;
            } elseif (in_array($t->text, [')', ']', '}'], true)) {
                $depth--;
            }

            $start ??= $i;
            $last = $i;
        }

        // Unreachable for parsed source: every list is closed
        throw new RuntimeException('Unterminated argument list');
    }

    /**
     * Index of the next significant token after $i
     *
     * @param list<PhpToken> $tokens
     */
    private static function next(array $tokens, int $i): int {
        $count = count($tokens);

        for ($i++; $i < $count && $tokens[$i]->isIgnorable(); $i++);

        return $i;
    }

    /**
     * The value of a plain string literal, null for anything else
     */
    private static function string_value(PhpToken $t): ?string {
        if (!$t->is(T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }

        $inner = substr($t->text, 1, -1);

        return $t->text[0] === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $inner)
            : stripcslashes($inner);
    }
}
