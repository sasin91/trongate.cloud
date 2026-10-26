<?php

/**
 * modules/log: Log and its writers.
 *
 * Run: php modules/log/tests/Log_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Log.php';

/** Keeps every message it is given */
final class Kept_lines implements Log_writer {
    /** @var list<string> */
    public array $lines = [];

    function write(string $level, string $message): void {
        $this->lines[] = "{$level} {$message}";
    }
}

function stream_output(callable $write): string {
    $stream = fopen('php://memory', 'w+');
    $write($stream);
    rewind($stream);

    return (string) stream_get_contents($stream);
}

test('a message goes to every writer whole; a blank one is left out', function () {
    $a = new Kept_lines();
    $b = new Kept_lines();
    $log = new Log([$a, $b]);
    $log->warn("one\ntwo\n");
    $log->info("  \n");

    assert_same(["warn one\ntwo"], $a->lines);
    assert_same($a->lines, $b->lines);
});

test('an unknown level is written as info', function () {
    $kept = new Kept_lines();
    (new Log([$kept]))->write('loud', 'x');

    assert_same(['info x'], $kept->lines);
});

test('a failing writer does not stop the others', function () {
    $broken = new class implements Log_writer {
        function write(string $level, string $message): void {
            throw new RuntimeException('down');
        }
    };
    $kept = new Kept_lines();
    (new Log([$broken, $kept]))->error('still here');

    assert_same(['error still here'], $kept->lines);
});

test('the stream writer writes a prefix and the level, or only the prefix', function () {
    $with = stream_output(fn($s) => (new Log([new Stream_writer('[task #3] ', true, $s)]))->info('hi'));
    $without = stream_output(fn($s) => (new Log([new Stream_writer('[Runtime] ', false, $s)]))->info('hi'));

    assert_same("[task #3] info: hi\n", $with);
    assert_same("[Runtime] hi\n", $without);
});

test('the error_log writer writes the prefix, the level and the line', function () {
    $file = tempnam(sys_get_temp_dir(), 'log-test');
    $before = ini_set('error_log', $file);
    (new Log([new Error_log_writer('[webhooks] ')]))->warn('Unknown event');
    ini_set('error_log', (string) $before);

    assert_contains('[webhooks] warn: Unknown event', (string) file_get_contents($file));
    unlink($file);
});

finish_tests();
