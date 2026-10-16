<?php

/**
 * Tests for Github::repository_from_link: the owner/name a pasted public
 * link deploys, and the links it refuses.
 *
 * Run: php tests/Github_link_test.php (exits non-zero on a failure)
 */

require_once dirname(__DIR__) . '/modules/services/clients/Client.php';
require_once dirname(__DIR__) . '/modules/services/clients/Github.php';

$failures = 0;

$test = function (string $name, callable $body) use (&$failures): void {
    try {
        $body();
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}\n     {$e->getMessage()}\n";
    }
};

$same = function ($expected, $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Expected:\n" . var_export($expected, true) . "\nGot:\n" . var_export($actual, true));
    }
};

$test('a repository link gives owner/name', function () use ($same) {
    $same('trongate/trongate-framework', Github::repository_from_link('https://github.com/trongate/trongate-framework'));
});

$test('without https://, with www., a trailing slash or .git', function () use ($same) {
    $same('owner/app', Github::repository_from_link('github.com/owner/app'));
    $same('owner/app', Github::repository_from_link('https://www.github.com/owner/app/'));
    $same('owner/app', Github::repository_from_link('https://github.com/owner/app.git'));
    $same('owner/app', Github::repository_from_link('  http://github.com/owner/app  '));
});

$test('a page inside the repository keeps owner/name only', function () use ($same) {
    $same('owner/app', Github::repository_from_link('https://github.com/owner/app/tree/feature/x'));
    $same('owner/app', Github::repository_from_link('https://github.com/owner/app?tab=readme'));
    $same('owner/app', Github::repository_from_link('https://github.com/owner/app#readme'));
});

$test('other hosts and addresses are refused', function () use ($same) {
    $same(null, Github::repository_from_link('https://gitlab.com/owner/app'));
    $same(null, Github::repository_from_link('https://github.com.evil.test/owner/app'));
    $same(null, Github::repository_from_link('https://evil.test/github.com/owner/app'));
    $same(null, Github::repository_from_link('https://user@github.com/owner/app'));
    $same(null, Github::repository_from_link('https://github.com:8443/owner/app'));
    $same(null, Github::repository_from_link('http://169.254.169.254/latest'));
    $same(null, Github::repository_from_link('git@github.com:owner/app.git'));
    $same(null, Github::repository_from_link('file:///etc/passwd'));
});

$test('owner/name alone is not a link', function () use ($same) {
    $same(null, Github::repository_from_link('owner/app'));
});

$test('an owner without a repository, or a dot path, is refused', function () use ($same) {
    $same(null, Github::repository_from_link('https://github.com/owner'));
    $same(null, Github::repository_from_link('https://github.com/owner/'));
    $same(null, Github::repository_from_link('https://github.com/owner/..'));
    $same(null, Github::repository_from_link('https://github.com/owner/.git'));
});

$test('an anonymous client sends no Authorization header', function () use ($same) {
    $names = array_map(fn(string $line) => strtolower(explode(':', $line, 2)[0]), Github::anonymous()->headers());
    $same(false, in_array('authorization', $names, true));
    $same(true, in_array('authorization', array_map(fn(string $line) => strtolower(explode(':', $line, 2)[0]), (new Github('t'))->headers()), true));
});

exit($failures === 0 ? 0 : 1);
