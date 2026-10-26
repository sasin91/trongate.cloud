<?php

/**
 * modules/log: Log and its handlers.
 *
 * Run: php tests/Log_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../modules/log/Log.php';

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

test('the error_log handler writes the prefix, the level and the line', function () {
    $file = tempnam(sys_get_temp_dir(), 'log-test');
    $before = ini_set('error_log', $file);
    (new Log([new Error_log_handler('[webhooks] ')]))->warn('Unknown event');
    ini_set('error_log', (string) $before);

    assert_contains('[webhooks] warn: Unknown event', (string) file_get_contents($file));
    unlink($file);
});

finish_tests();
