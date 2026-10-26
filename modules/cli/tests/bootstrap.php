<?php

/**
 * Shared by every test in this folder.
 *
 * test() runs one named check and prints "ok" or "FAIL"; the assert_*
 * functions throw when a check does not hold; finish_tests() prints the
 * summary and exits non-zero after any failure.
 */

final class Test_run {
    public static int $failures = 0;
}

function test(string $name, callable $body): void {
    try {
        $body();
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        Test_run::$failures++;
        echo "FAIL {$name}\n     {$e->getMessage()}\n";
    }
}

/**
 * A failed check outside test(), for tests that walk a list of files.
 */
function fail(string $message): void {
    Test_run::$failures++;
    echo "FAIL {$message}\n";
}

function assert_same(mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Expected:\n" . var_export($expected, true) . "\nGot:\n" . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("Expected to find '{$needle}' in:\n{$haystack}");
    }
}

/**
 * $body must throw a RuntimeException whose message contains $needle.
 */
function assert_throws(callable $body, string $needle): void {
    $message = null;

    try {
        $body();
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    if ($message === null) {
        throw new LogicException('Expected a RuntimeException');
    }

    if (!str_contains($message, $needle)) {
        throw new LogicException("Expected a message with '{$needle}', got: {$message}");
    }
}

function finish_tests(): never {
    if (Test_run::$failures > 0) {
        echo "\n" . Test_run::$failures . " failed\n";
        exit(1);
    }

    echo "\nAll tests passed\n";
    exit(0);
}
