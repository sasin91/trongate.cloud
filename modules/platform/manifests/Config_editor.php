<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
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
 * Sets global constants in a customer's PHP config file: parses it into an
 * AST (nikic/php-parser), sets the value of every define('NAME', ...) call and
 * `const NAME = ...;` for the given names, and prints it back with the
 * format-preserving printer, so only those values change and the rest of the
 * file (formatting, comments, other code) stays as it was.
 *
 * Every definition is changed, wherever it is (if/else branches, a
 * `defined() ||` guard), whatever its quoting or argument style; class
 * constants are left alone and a constant the file does not define is
 * appended. The result is parsed again, checked to define exactly the
 * requested values, and compiled with `php -l` before it is returned.
 *
 * Runs in the worker only: customer images carry no copy of the parser.
 */
final class Config_editor {

    /**
     * @param array<string, string> $values constant name => value (written as a string literal)
     * @throws RuntimeException when the source is not valid PHP or the result is not what was asked for
     */
    static function set_constants(string $source, array $values): string {
        $parser = self::parser();
        $old = self::parse($parser, $source, 'The file is not valid PHP');
        $tokens = $parser->getTokens();

        // The format-preserving printer diffs the edited tree against the original
        $new = (new NodeTraverser(new CloningVisitor()))->traverse($old);
        $found = [];

        foreach (self::definitions($new) as [$name, $set]) {
            if (array_key_exists($name, $values)) {
                $set(new String_($values[$name]));
                $found[$name] = true;
            }
        }

        $printer = new Standard();
        $out = $printer->printFormatPreserving($new, $old, $tokens);
        $missing = [];

        foreach ($values as $name => $value) {
            if (!isset($found[$name])) {
                $missing[] = new Stmt\Expression(new FuncCall(new Name('define'), [
                    new Arg(new String_((string) $name)),
                    new Arg(new String_($value)),
                ]));
            }
        }

        if ($missing) {
            // After a closing tag, appended code would be output; reopen PHP instead
            $real = array_filter($tokens, fn($token) => $token->id !== 0); // drops the end-of-file sentinel
            $last = end($real);
            $in_php = $last !== false && !$last->is([T_CLOSE_TAG, T_INLINE_HTML]);
            $out = rtrim($out, "\n") . "\n" . ($in_php ? '' : "<?php\n") . $printer->prettyPrint($missing) . "\n";
        }

        self::verify($out, $values);
        self::lint($out);

        return $out;
    }

    /**
     * Fail unless $source parses and every definition of each name is exactly its value
     *
     * @param array<string, string> $values
     */
    static function verify(string $source, array $values): void {
        $stmts = self::parse(self::parser(), $source, 'The regenerated file is not valid PHP');
        $defined = [];

        foreach (self::definitions($stmts) as [$name, , $value]) {
            if (!array_key_exists($name, $values)) {
                continue;
            }

            if (!$value instanceof String_ || $value->value !== $values[$name]) {
                throw new RuntimeException("The regenerated file does not set {$name} to the expected value");
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
     * Compile $source with `php -l`, which also catches code that parses but
     * cannot compile (a constant declared twice with const, say)
     */
    static function lint(string $source): void {
        $file = (string) tempnam(sys_get_temp_dir(), 'tgc-config');
        $output = [];
        $code = 1;

        try {
            file_put_contents($file, $source);
            exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        } finally {
            unlink($file);
        }

        if ($code !== 0) {
            $lines = array_filter($output, fn(string $line) => trim($line) !== '' && !str_starts_with($line, 'Errors parsing'));
            $message = trim((string) preg_replace('/\s+/', ' ', str_replace($file, 'config.php', implode(' ', $lines))));

            throw new RuntimeException("The regenerated file does not compile ({$message})");
        }
    }

    /**
     * Each global constant definition in the tree: define() calls with a
     * literal name, and the constants of `const` statements (not class
     * constants). The setter replaces the value node in place.
     *
     * @param Node[] $stmts
     * @return list<array{0: string, 1: Closure(Expr): void, 2: Expr}> [name, set value, current value]
     */
    static function definitions(array $stmts): array {
        $definitions = [];
        $nodes = (new NodeFinder())->find($stmts, fn(Node $node) => $node instanceof FuncCall || $node instanceof Stmt\Const_);

        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Const_) {
                foreach ($node->consts as $const) {
                    $definitions[] = [$const->name->toString(), function (Expr $value) use ($const) {
                        $const->value = $value;
                    }, $const->value];
                }

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
