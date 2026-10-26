<?php

/**
 * modules/cli: Log and its handlers, Env, Command and Pipeline.
 *
 * Run: php tests/Cli_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../modules/cli/Log.php';
require_once __DIR__ . '/../modules/cli/Env.php';
require_once __DIR__ . '/../modules/cli/Command.php';
require_once __DIR__ . '/../modules/cli/Pipeline.php';

/** Keeps every line it is given */
final class Kept_lines implements Log_handler {
    /** @var list<string> */
    public array $lines = [];

    function handle(string $level, string $line): void {
        $this->lines[] = "{$level} {$line}";
    }
}

function stream_output(callable $write): string {
    $stream = fopen('php://memory', 'w+');
    $write($stream);
    rewind($stream);

    return (string) stream_get_contents($stream);
}

test('a message goes to every handler one line at a time, blank lines left out', function () {
    $a = new Kept_lines();
    $b = new Kept_lines();
    (new Log([$a, $b]))->warn("one\n\ntwo\n");

    assert_same(['warn one', 'warn two'], $a->lines);
    assert_same($a->lines, $b->lines);
});

test('an unknown level is written as info', function () {
    $kept = new Kept_lines();
    (new Log([$kept]))->write('loud', 'x');

    assert_same(['info x'], $kept->lines);
});

test('a failing handler does not stop the others', function () {
    $broken = new class implements Log_handler {
        function handle(string $level, string $line): void {
            throw new RuntimeException('down');
        }
    };
    $kept = new Kept_lines();
    (new Log([$broken, $kept]))->error('still here');

    assert_same(['error still here'], $kept->lines);
});

test('the stream handler writes a prefix and the level, or only the prefix', function () {
    $with = stream_output(fn($s) => (new Log([new Stream_handler('[task #3] ', true, $s)]))->info('hi'));
    $without = stream_output(fn($s) => (new Log([new Stream_handler('[Runtime] ', false, $s)]))->info('hi'));

    assert_same("[task #3] info: hi\n", $with);
    assert_same("[Runtime] hi\n", $without);
});

test('Env::required returns the value, and refuses a missing one', function () {
    putenv('CLI_TEST_SETTING=yes');
    assert_same('yes', Env::required('CLI_TEST_SETTING'));
    putenv('CLI_TEST_SETTING');
    assert_throws(fn() => Env::required('CLI_TEST_SETTING'), 'CLI_TEST_SETTING is not set');
});

test('Command::run passes on success and throws on a non-zero exit', function () {
    Command::run(['true']);
    assert_throws(fn() => Command::run(['false']), 'false exited with 1');
});

test('a pipeline runs its actions in order and returns 0', function () {
    $done = [];
    $kept = new Kept_lines();
    $pipeline = new Pipeline(new Log([$kept]), ['go' => [
        function () use (&$done) { $done[] = 'a'; },
        function () use (&$done) { $done[] = 'b'; },
    ]]);

    assert_same(0, $pipeline->run('go'));
    assert_same(['a', 'b'], $done);
    assert_same(['info go done'], $kept->lines);
});

test('a failing action stops the pipeline, logs why, cleans up and returns 1', function () {
    $done = [];
    $cleaned = null;
    $kept = new Kept_lines();
    $pipeline = new Pipeline(new Log([$kept]), ['go' => [
        function () { throw new RuntimeException('disk full'); },
        function () use (&$done) { $done[] = 'never'; },
    ]], function (string $name) use (&$cleaned) { $cleaned = $name; });

    assert_same(1, $pipeline->run('go'));
    assert_same([], $done);
    assert_same('go', $cleaned);
    assert_same(['error go failed: disk full'], $kept->lines);
});

test('an unknown pipeline prints the usage and returns 1', function () {
    $kept = new Kept_lines();
    $pipeline = new Pipeline(new Log([$kept]), ['dump' => [], 'upload' => []]);

    assert_same(1, $pipeline->run('nope'));
    assert_same(['error Usage: dump|upload'], $kept->lines);
});

finish_tests();
