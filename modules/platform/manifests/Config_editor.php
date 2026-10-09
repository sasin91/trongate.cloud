<?php

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/Config_rule.php';
require_once __DIR__ . '/Config_error.php';

use PhpParser\BuilderHelpers;
use PhpParser\ConstExprEvaluator;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Applies Config_rules to an app's config/*.php files: parses each file into
 * an AST (nikic/php-parser), sets every value a rule's key matches, and prints
 * the file back with the format-preserving printer, so only those values
 * change and the rest (formatting, comments, other code) stays as it was.
 *
 * A constant key matches every define('NAME', ...) call (any quoting,
 * \define, DEFINE, named arguments, in if/else or a `defined() ||` guard) and
 * global `const NAME = ...;`, never a class constant. A dotted key such as
 * databases.default.host matches $databases['default']['host'] in any
 * assignment to the variable or a part of it: `$databases = [...]`,
 * `$databases['default'] = [...]` or `$databases['default']['host'] = ...`.
 * A key found nowhere is appended to its file.
 *
 * Each edited file is parsed again and checked to hold exactly the rules'
 * values before it is returned. Nothing is written to disk or run: the
 * customer's code is only ever parsed, within check_limits().
 *
 * Runs in the worker only: customer images carry no copy of the parser.
 */
final class Config_editor {

    /** Limits on the customer's config/*.php (check_limits) */
    const MAX_FILES = 50;
    const MAX_FILE_BYTES = 256 * 1024;
    const MAX_TOTAL_BYTES = 512 * 1024;
    const MAX_DEPTH = 64;

    /**
     * @param array<string, string> $files the app's config/*.php: file name => source
     * @param list<Config_rule> $rules
     * @return array<string, string> the files the rules edited or created: file name => source
     * @throws Config_error when a file is not valid PHP or the result is not what was asked for
     */
    static function apply(array $files, array $rules): array {
        self::check_limits($files);
        $edited = [];
        $found = [];

        foreach ($files as $file => $source) {
            $scoped = array_filter($rules, fn(Config_rule $rule) => $rule->applies_to($file));

            if ($scoped && ($out = self::edit($file, $source, $scoped, $found)) !== null) {
                $edited[$file] = $out;
            }
        }

        $missing = [];

        foreach ($rules as $i => $rule) {
            if (!isset($found[$i])) {
                $missing[$rule->target_file()][] = $rule;
            }
        }

        foreach ($missing as $file => $file_rules) {
            $edited[$file] = self::append($file, $edited[$file] ?? $files[$file] ?? "<?php\n", $file_rules);
        }

        self::verify($edited + $files, $rules);

        return $edited;
    }

    /**
     * Fail unless every file parses and every match of each rule in its
     * scope holds the rule's value, with at least one match per rule
     *
     * @param array<string, string> $files file name => source
     * @param list<Config_rule> $rules
     */
    static function verify(array $files, array $rules): void {
        $evaluator = new ConstExprEvaluator();
        $set = [];

        foreach ($files as $file => $source) {
            $stmts = null;

            foreach ($rules as $i => $rule) {
                if (!$rule->applies_to($file)) {
                    continue;
                }

                $stmts ??= self::parse(self::parser(), $source, $file, Config_error::PLATFORM);

                foreach (self::matches($stmts, $rule, $file) as [, $value]) {
                    if ($evaluator->evaluateSilently($value) !== $rule->value) {
                        throw new Config_error("{$rule->key} does not hold the expected value after editing.", Config_error::PLATFORM, $file);
                    }

                    $set[$i] = true;
                }
            }
        }

        foreach ($rules as $i => $rule) {
            if (!isset($set[$i])) {
                throw new Config_error("{$rule->key} is not set after editing.", Config_error::PLATFORM, $rule->target_file());
            }
        }
    }

    /**
     * Fail unless every file parses: the deploy task checks the repository's
     * config before building
     *
     * @param array<string, string> $files file name => source
     */
    static function check(array $files): void {
        self::check_limits($files);

        foreach ($files as $file => $source) {
            self::parse(self::parser(), $source, $file);
        }
    }

    /**
     * The files are the customer's, parsed in the worker that runs every
     * app's builds: refuse what could exhaust its memory or stack before
     * parsing (config files are small and flat; these limits are far above
     * any real one)
     *
     * @param array<string, string> $files file name => source
     */
    static function check_limits(array $files): void {
        if (count($files) > self::MAX_FILES) {
            throw new Config_error('More than ' . self::MAX_FILES . ' PHP files in config/.', Config_error::FIX_FILE);
        }

        if (array_sum(array_map('strlen', $files)) > self::MAX_TOTAL_BYTES) {
            throw new Config_error('The PHP files in config/ are larger than ' . (self::MAX_TOTAL_BYTES >> 10) . ' KiB together.', Config_error::FIX_FILE);
        }

        foreach ($files as $file => $source) {
            if (!preg_match(Config_rule::FILE_PATTERN, (string) $file)) {
                throw new Config_error('File names in config/ may only use letters, digits, - and _.', Config_error::FIX_FILE, (string) $file);
            }

            if (strlen($source) > self::MAX_FILE_BYTES) {
                throw new Config_error('Larger than ' . (self::MAX_FILE_BYTES >> 10) . ' KiB.', Config_error::FIX_FILE, (string) $file);
            }

            if (!mb_check_encoding($source, 'UTF-8')) {
                throw new Config_error('Not saved as UTF-8.', Config_error::FIX_FILE, (string) $file);
            }

            // Bracket depth from the lexer alone, before anything recurses over a tree
            $depth = 0;
            $deepest = ['depth' => 0, 'line' => 0];

            foreach (PhpToken::tokenize($source) as $token) {
                $opens = in_array($token->text, ['(', '[', '{', '${'], true) || $token->is(T_CURLY_OPEN);
                $depth += $opens ? 1 : (in_array($token->text, [')', ']', '}'], true) ? -1 : 0);

                if ($depth > $deepest['depth']) {
                    $deepest = ['depth' => $depth, 'line' => $token->line];
                }
            }

            if ($deepest['depth'] > self::MAX_DEPTH) {
                throw new Config_error('Nested more than ' . self::MAX_DEPTH . ' levels deep.', Config_error::FIX_FILE, (string) $file, $deepest['line']);
            }
        }
    }

    /**
     * Every key a config file sets (Config_rule keys: constants, and
     * variable.key paths down to each non-array value), with its value when
     * that is a constant scalar expression. Used to find the settings a newer
     * Trongate adds to a config file.
     *
     * @return array<string, array{literal: bool, value: string|int|float|bool|null}>
     * @throws Config_error when the file is not valid PHP
     */
    static function settings(string $file, string $source): array {
        self::check_limits([$file => $source]);
        $stmts = self::parse(self::parser(), $source, $file);
        $finder = new NodeFinder();
        $settings = [];

        foreach (self::constants($finder, $stmts, $file) as [$name, , $value]) {
            $settings[$name] = self::setting($value);
        }

        foreach ($finder->findInstanceOf($stmts, Expr\Assign::class) as $assign) {
            $target = $assign->var;
            $keys = [];

            while ($target instanceof Expr\ArrayDimFetch && $target->dim instanceof String_) {
                array_unshift($keys, $target->dim->value);
                $target = $target->var;
            }

            if ($target instanceof Expr\Variable && is_string($target->name)) {
                self::leaves([$target->name, ...$keys], $assign->expr, $settings);
            }
        }

        return $settings;
    }

    /**
     * Add the leaves of a value set at $path to $settings: one entry per
     * non-array value, nested string-keyed array literals followed down
     *
     * @param list<string> $path variable, then keys
     * @param array<string, array{literal: bool, value: string|int|float|bool|null}> $settings
     */
    private static function leaves(array $path, Expr $value, array &$settings): void {
        if ($value instanceof Expr\Array_ && $value->items !== []) {
            $keyed = [];

            foreach ($value->items as $item) {
                if ($item->key instanceof String_ && !$item->unpack) {
                    $keyed[$item->key->value] = $item->value;
                }
            }

            if (count($keyed) === count($value->items)) {
                foreach ($keyed as $key => $item_value) {
                    self::leaves([...$path, (string) $key], $item_value, $settings);
                }

                return;
            }
        }

        // A variable alone, or a key with a dot, is no Config_rule key
        if (count($path) > 1 && preg_match(Config_rule::KEY_PATTERN, implode('.', $path)) && !in_array('', $path, true)) {
            $settings[implode('.', $path)] = self::setting($value);
        }
    }

    /**
     * @return array{literal: bool, value: string|int|float|bool|null}
     */
    private static function setting(Expr $value): array {
        try {
            $evaluated = (new ConstExprEvaluator())->evaluateDirectly($value);
        } catch (Throwable) {
            return ['literal' => false, 'value' => null];
        }

        return is_scalar($evaluated) || $evaluated === null
            ? ['literal' => true, 'value' => $evaluated]
            : ['literal' => false, 'value' => null];
    }

    /**
     * Set the rules' values in one file; null when no rule matches in it
     *
     * @param array<int, Config_rule> $rules
     * @param array<int, true> $found rule index => matched, updated
     */
    private static function edit(string $file, string $source, array $rules, array &$found): ?string {
        $parser = self::parser();
        $old = self::parse($parser, $source, $file);
        $tokens = $parser->getTokens();

        // The format-preserving printer diffs the edited tree against the original
        $new = (new NodeTraverser(new CloningVisitor()))->traverse($old);
        $matched = false;

        foreach ($rules as $i => $rule) {
            foreach (self::matches($new, $rule, $file) as [$set]) {
                $set(BuilderHelpers::normalizeValue($rule->value));
                $found[$i] = true;
                $matched = true;
            }
        }

        return $matched ? (new Standard())->printFormatPreserving($new, $old, $tokens) : null;
    }

    /**
     * Append statements setting the given rules' keys to a file
     *
     * @param list<Config_rule> $rules
     */
    private static function append(string $file, string $source, array $rules): string {
        $parser = self::parser();
        self::parse($parser, $source, $file);
        $stmts = [];

        foreach ($rules as $rule) {
            $value = BuilderHelpers::normalizeValue($rule->value);

            if ($rule->constant !== null) {
                $stmts[] = new Stmt\Expression(new FuncCall(new Name('define'), [new Arg(new String_($rule->constant)), new Arg($value)]));
                continue;
            }

            $target = new Expr\Variable((string) $rule->variable);

            foreach ($rule->path as $key) {
                $target = new Expr\ArrayDimFetch($target, new String_($key));
            }

            $stmts[] = new Stmt\Expression(new Expr\Assign($target, $value));
        }

        // After a closing tag, appended code would be output; reopen PHP instead
        $real = array_filter($parser->getTokens(), fn($token) => $token->id !== 0); // drops the end-of-file sentinel
        $last = end($real);
        $in_php = $last !== false && !$last->is([T_CLOSE_TAG, T_INLINE_HTML]);

        return rtrim($source, "\n") . "\n" . ($in_php ? '' : "<?php\n") . (new Standard())->prettyPrint($stmts) . "\n";
    }

    /**
     * Every place in the tree that sets a rule's key
     *
     * @param Node[] $stmts
     * @return list<array{0: Closure(Expr): void, 1: Expr}> [replace the value, current value]
     */
    private static function matches(array $stmts, Config_rule $rule, string $file): array {
        $finder = new NodeFinder();

        if ($rule->constant !== null) {
            return array_values(array_map(
                fn(array $definition) => [$definition[1], $definition[2]],
                array_filter(self::constants($finder, $stmts, $file), fn(array $definition) => $definition[0] === $rule->constant),
            ));
        }

        $matches = [];

        foreach ($finder->findInstanceOf($stmts, Expr\Assign::class) as $assign) {
            $dims = self::array_path($assign->var, (string) $rule->variable);

            if ($dims === null || array_slice($rule->path, 0, count($dims)) !== $dims) {
                continue;
            }

            $rest = array_slice($rule->path, count($dims));

            if ($rest === []) {
                $matches[] = [function (Expr $value) use ($assign) {
                    $assign->expr = $value;
                }, $assign->expr];
                continue;
            }

            foreach (self::array_items($assign->expr, $rest) as $item) {
                $matches[] = [function (Expr $value) use ($item) {
                    $item->value = $value;
                }, $item->value];
            }
        }

        return $matches;
    }

    /**
     * Each global constant definition: define() calls with a literal name and
     * the constants of `const` statements (not class constants)
     *
     * @param Node[] $stmts
     * @return list<array{0: string, 1: Closure(Expr): void, 2: Expr}> [name, replace the value, current value]
     */
    private static function constants(NodeFinder $finder, array $stmts, string $file): array {
        $definitions = [];

        foreach ($finder->find($stmts, fn(Node $node) => $node instanceof FuncCall || $node instanceof Stmt\Const_) as $node) {
            if ($node instanceof Stmt\Const_) {
                foreach ($node->consts as $const) {
                    $definitions[] = [$const->name->toString(), function (Expr $value) use ($const) {
                        $const->value = $value;
                    }, $const->value];
                }

                continue;
            }

            if (!$node instanceof FuncCall) {
                continue;
            }

            [$name, $value] = self::define_arguments($node);

            if ($name === null) {
                continue;
            }

            if ($value === null) {
                throw new Config_error("define('{$name}') has no value.", Config_error::FIX_FILE, $file, $node->getStartLine());
            }

            $definitions[] = [$name, function (Expr $expr) use ($value) {
                $value->value = $expr;
            }, $value->value];
        }

        return $definitions;
    }

    /**
     * The constant name (a string literal) and the value argument of a
     * define() call, positional or named; [null, null] for any other call
     *
     * @return array{0: ?string, 1: ?Arg}
     */
    private static function define_arguments(FuncCall $call): array {
        // define and \define, in any case; not Some\define or $fn()
        if (!$call->name instanceof Name || strtolower($call->name->toString()) !== 'define' || $call->isFirstClassCallable()) {
            return [null, null];
        }

        $name = null;
        $value = null;
        $position = 0;

        foreach ($call->getArgs() as $arg) {
            if ($arg->unpack) {
                return [null, null];
            }

            $param = $arg->name?->toLowerString() ?? (['constant_name', 'value'][$position++] ?? null);

            if ($param === 'constant_name') {
                $name = $arg->value;
            } elseif ($param === 'value') {
                $value = $arg;
            }
        }

        return $name instanceof String_ ? [$name->value, $value] : [null, null];
    }

    /**
     * The literal keys of an assignment target such as $variable['a']['b']
     * (['a', 'b']); null for any other target
     *
     * @return ?list<string>
     */
    private static function array_path(Expr $target, string $variable): ?array {
        $dims = [];

        while ($target instanceof Expr\ArrayDimFetch) {
            if (!$target->dim instanceof String_) {
                return null;
            }

            array_unshift($dims, $target->dim->value);
            $target = $target->var;
        }

        return $target instanceof Expr\Variable && $target->name === $variable ? $dims : null;
    }

    /**
     * The items of an array literal at a key path, through nested literals
     *
     * @param list<string> $path
     * @return list<ArrayItem>
     */
    private static function array_items(Expr $array, array $path): array {
        if (!$array instanceof Expr\Array_) {
            return [];
        }

        $key = array_shift($path);
        $items = [];

        foreach ($array->items as $item) {
            if (!$item->key instanceof String_ || $item->key->value !== $key) {
                continue;
            }

            array_push($items, ...($path === [] ? [$item] : self::array_items($item->value, $path)));
        }

        return $items;
    }

    private static function parser(): Parser {
        return (new ParserFactory())->createForHostVersion();
    }

    /**
     * The file's statements; a syntax error is the app's to fix, unless the
     * file is one we just edited
     *
     * @return Stmt[]
     */
    private static function parse(Parser $parser, string $source, string $file, string $action = Config_error::FIX_FILE): array {
        try {
            return $parser->parse($source) ?? [];
        } catch (Error $e) {
            $line = $e->getStartLine();

            throw new Config_error("Not valid PHP: {$e->getRawMessage()}.", $action, $file, $line > 0 ? $line : null, $e);
        }
    }
}
