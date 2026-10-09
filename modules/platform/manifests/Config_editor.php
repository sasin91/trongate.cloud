<?php

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/Config_rule.php';

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
 * Each edited file is parsed again, checked to hold exactly the rules' values
 * and compiled with `php -l` before it is returned.
 *
 * Runs in the worker only: customer images carry no copy of the parser.
 */
final class Config_editor {

    /**
     * @param array<string, string> $files the app's config/*.php: file name => source
     * @param list<Config_rule> $rules
     * @return array<string, string> the files the rules edited or created: file name => source
     * @throws RuntimeException when a file is not valid PHP or the result is not what was asked for
     */
    static function apply(array $files, array $rules): array {
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

        foreach ($edited as $file => $source) {
            self::lint($file, $source);
        }

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

                $stmts ??= self::parse(self::parser(), $source, "{$file} is not valid PHP after editing");

                foreach (self::matches($stmts, $rule) as [, $value]) {
                    if ($evaluator->evaluateSilently($value) !== $rule->value) {
                        throw new RuntimeException("{$file} does not set {$rule->key} to the expected value after editing");
                    }

                    $set[$i] = true;
                }
            }
        }

        foreach ($rules as $i => $rule) {
            if (!isset($set[$i])) {
                throw new RuntimeException("{$rule->target_file()} does not set {$rule->key} after editing");
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
        foreach ($files as $file => $source) {
            self::parse(self::parser(), $source, "{$file} is not valid PHP");
        }
    }

    /**
     * Compile a file with `php -l`, which also catches code that parses but
     * cannot compile (a function declared twice, say)
     */
    static function lint(string $file, string $source): void {
        $path = (string) tempnam(sys_get_temp_dir(), 'tgc-config');
        $output = [];
        $code = 1;

        try {
            file_put_contents($path, $source);
            exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
        } finally {
            unlink($path);
        }

        if ($code !== 0) {
            $lines = array_filter($output, fn(string $line) => trim($line) !== '' && !str_starts_with($line, 'Errors parsing'));
            $message = trim((string) preg_replace('/\s+/', ' ', str_replace($path, $file, implode(' ', $lines))));

            throw new RuntimeException("{$file} does not compile after editing ({$message})");
        }
    }

    /**
     * Set the rules' values in one file; null when no rule matches in it
     *
     * @param array<int, Config_rule> $rules
     * @param array<int, true> $found rule index => matched, updated
     */
    private static function edit(string $file, string $source, array $rules, array &$found): ?string {
        $parser = self::parser();
        $old = self::parse($parser, $source, "{$file} is not valid PHP");
        $tokens = $parser->getTokens();

        // The format-preserving printer diffs the edited tree against the original
        $new = (new NodeTraverser(new CloningVisitor()))->traverse($old);
        $matched = false;

        foreach ($rules as $i => $rule) {
            try {
                $matches = self::matches($new, $rule);
            } catch (RuntimeException $e) {
                throw new RuntimeException("{$file}: {$e->getMessage()}", 0, $e);
            }

            foreach ($matches as [$set]) {
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
        self::parse($parser, $source, "{$file} is not valid PHP");
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
    private static function matches(array $stmts, Config_rule $rule): array {
        $finder = new NodeFinder();

        if ($rule->constant !== null) {
            return array_values(array_map(
                fn(array $definition) => [$definition[1], $definition[2]],
                array_filter(self::constants($finder, $stmts), fn(array $definition) => $definition[0] === $rule->constant),
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
    private static function constants(NodeFinder $finder, array $stmts): array {
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
                throw new RuntimeException("define('{$name}') on line {$node->getStartLine()} has no value");
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
     * @return Stmt[]
     */
    private static function parse(Parser $parser, string $source, string $error): array {
        try {
            return $parser->parse($source) ?? [];
        } catch (Error $e) {
            throw new RuntimeException("{$error} ({$e->getMessage()})", 0, $e);
        }
    }
}
