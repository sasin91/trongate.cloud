<?php

/**
 * Rewrites define('NAME', ...) calls in PHP source to define('NAME', 'value'),
 * appending the define when the source has none.
 *
 * Works on tokens, so a define whose value spans several lines (a closure, a
 * ternary over getenv) is replaced whole. Strings and comments that merely
 * mention define('NAME') are left alone.
 *
 * Standalone and dependency-free: Trongate_config::patch_script embeds this
 * file's body in `php -r` and runs it inside the customer image.
 *
 * @param array<string, string> $defines
 */
function tgc_patch_defines(string $source, array $defines): string {
    $tokens = token_get_all($source);
    $count = count($tokens);
    $text = static fn ($t): string => is_array($t) ? $t[1] : $t;
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $next = static function (int $i) use ($tokens, $count, $skip): int {
        for ($i++; $i < $count && is_array($tokens[$i]) && in_array($tokens[$i][0], $skip, true); $i++);

        return $i;
    };
    $quote = static fn (string $v): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";

    // Token index of each define's '(' => [index of matching ')', constant name]
    $spans = [];
    $prev = null;

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];

        if (is_array($t) && in_array($t[0], $skip, true)) {
            continue;
        }

        $is_call = is_array($t)
            && in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            && strcasecmp(ltrim($t[1], '\\'), 'define') === 0
            && !(is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true));
        $prev = $t;

        if (!$is_call) {
            continue;
        }

        $open = $next($i);

        if (($tokens[$open] ?? null) !== '(') {
            continue;
        }

        $arg = $next($open);

        if (!is_array($tokens[$arg] ?? null) || $tokens[$arg][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $name = substr($tokens[$arg][1], 1, -1);

        if (!array_key_exists($name, $defines)) {
            continue;
        }

        $depth = 0;

        for ($j = $open; $j < $count; $j++) {
            $s = $text($tokens[$j]);

            if ($s === '(' || $s === '[' || $s === '{' || (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($s === ')' || $s === ']' || $s === '}') {
                $depth--;
            }

            if ($depth === 0) {
                break;
            }
        }

        if ($j >= $count) {
            throw new RuntimeException("Unbalanced define('{$name}', ...)");
        }

        $spans[$open] = [$j, $name];
        $i = $j;
    }

    $out = '';
    $found = [];

    for ($i = 0; $i < $count; $i++) {
        if (isset($spans[$i])) {
            [$end, $name] = $spans[$i];
            $out .= '(' . $quote($name) . ', ' . $quote($defines[$name]) . ')';
            $found[$name] = true;
            $i = $end;

            continue;
        }

        $out .= $text($tokens[$i]);
    }

    $missing = '';

    foreach ($defines as $name => $value) {
        if (!isset($found[$name])) {
            $missing .= 'define(' . $quote((string) $name) . ', ' . $quote($value) . ");\n";
        }
    }

    if ($missing === '') {
        return $out;
    }

    /* After a closing tag, appended code would be output; reopen PHP instead. */
    $last = $tokens[$count - 1] ?? null;
    $in_php = $count > 0 && !(is_array($last) && in_array($last[0], [T_CLOSE_TAG, T_INLINE_HTML], true));

    return rtrim($out, "\n") . "\n" . ($in_php ? '' : "<?php\n") . $missing;
}
