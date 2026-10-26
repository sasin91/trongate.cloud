<?php

/**
 * modules/cli: Env and Process.
 *
 * Run: php modules/cli/tests/Cli_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Env.php';
require_once __DIR__ . '/../Process.php';

test('Env::required returns the value, and refuses a missing one', function () {
    putenv('CLI_TEST_SETTING=yes');
    assert_same('yes', Env::required('CLI_TEST_SETTING'));
    putenv('CLI_TEST_SETTING');
    assert_throws(fn() => Env::required('CLI_TEST_SETTING'), 'CLI_TEST_SETTING is not set');
});

test('a process keeps its output byte for byte when there is no callback', function () {
    $process = new Process(['sh', '-c', 'printf "one\ntwo"; printf "oops" >&2; exit 1']);

    assert_same(1, $process->run());
    assert_same("one\ntwo", $process->output());
    assert_same('oops', $process->error_output());
    assert_same(false, $process->is_successful());
});

test('a callback gets each line as it comes, from both streams', function () {
    $lines = [];
    (new Process(['sh', '-c', 'echo a; echo b >&2; printf c']))->run(function (string $type, string $line) use (&$lines) {
        $lines[] = "{$type} {$line}";
    });
    sort($lines);

    assert_same(['err b', 'out a', 'out c'], $lines);
});

test('output without line breaks reaches the callback in bounded pieces', function () {
    $largest = 0;
    $total = 0;
    (new Process(['head', '-c', '3000000', '/dev/zero']))->run(function (string $type, string $piece) use (&$largest, &$total) {
        $largest = max($largest, strlen($piece));
        $total += strlen($piece);
    });

    assert_same(3000000, $total);
    assert_same(true, $largest <= Process::MAX_LINE_BYTES + Process::READ_BYTES);
});

test('must_run passes on success and throws with the error output otherwise', function () {
    assert_same(0, (new Process(['true']))->must_run()->exit_code());
    assert_throws(fn() => (new Process(['sh', '-c', 'echo nope >&2; exit 3']))->must_run(), "sh -c 'echo nope >&2; exit 3' exited with 3: nope");
});

test('a process runs in its folder with its environment', function () {
    $process = new Process(['sh', '-c', 'pwd; echo "$GREETING"'], '/tmp', ['GREETING' => 'hej']);
    $process->run();

    assert_same(realpath('/tmp') . "\nhej\n", $process->output());
});

test('a process that runs past its timeout is stopped', function () {
    $started = microtime(true);
    $process = new Process(['sleep', '30'], timeout: 1);

    assert_throws(fn() => $process->must_run(), 'timed out after 1 seconds');
    assert_same(true, $process->timed_out());
    assert_same(true, microtime(true) - $started < 10);
});

test('a process runs once; running it again is refused', function () {
    $process = new Process(['true']);
    $process->run();

    try {
        $process->run();
        $refused = false;
    } catch (LogicException $e) {
        $refused = $e->getMessage() === 'true has already run; make a new Process';
    }

    assert_same(true, $refused);
});

test('a program that does not exist cannot start', function () {
    assert_throws(fn() => (new Process(['/no/such/program']))->run(), 'Cannot start /no/such/program');
});

finish_tests();
