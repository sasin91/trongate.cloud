<?php

/**
 * Tests for Github::repository_from_link: the owner/name a pasted public
 * link deploys, and the links it refuses.
 *
 * Run: php tests/Github_link_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/modules/services/clients/Client.php';
require_once dirname(__DIR__) . '/modules/services/clients/Github.php';

test('a repository link gives owner/name', function () {
    assert_same('trongate/trongate-framework', Github::repository_from_link('https://github.com/trongate/trongate-framework'));
});

test('without https://, with www., a trailing slash or .git', function () {
    assert_same('owner/app', Github::repository_from_link('github.com/owner/app'));
    assert_same('owner/app', Github::repository_from_link('https://www.github.com/owner/app/'));
    assert_same('owner/app', Github::repository_from_link('https://github.com/owner/app.git'));
    assert_same('owner/app', Github::repository_from_link('  http://github.com/owner/app  '));
});

test('a page inside the repository keeps owner/name only', function () {
    assert_same('owner/app', Github::repository_from_link('https://github.com/owner/app/tree/feature/x'));
    assert_same('owner/app', Github::repository_from_link('https://github.com/owner/app?tab=readme'));
    assert_same('owner/app', Github::repository_from_link('https://github.com/owner/app#readme'));
});

test('other hosts and addresses are refused', function () {
    assert_same(null, Github::repository_from_link('https://gitlab.com/owner/app'));
    assert_same(null, Github::repository_from_link('https://github.com.evil.test/owner/app'));
    assert_same(null, Github::repository_from_link('https://evil.test/github.com/owner/app'));
    assert_same(null, Github::repository_from_link('https://user@github.com/owner/app'));
    assert_same(null, Github::repository_from_link('https://github.com:8443/owner/app'));
    assert_same(null, Github::repository_from_link('http://169.254.169.254/latest'));
    assert_same(null, Github::repository_from_link('git@github.com:owner/app.git'));
    assert_same(null, Github::repository_from_link('file:///etc/passwd'));
});

test('owner/name alone is not a link', function () {
    assert_same(null, Github::repository_from_link('owner/app'));
});

test('an owner without a repository, or a dot path, is refused', function () {
    assert_same(null, Github::repository_from_link('https://github.com/owner'));
    assert_same(null, Github::repository_from_link('https://github.com/owner/'));
    assert_same(null, Github::repository_from_link('https://github.com/owner/..'));
    assert_same(null, Github::repository_from_link('https://github.com/owner/.git'));
});

test('an anonymous client sends no Authorization header', function () {
    $names = array_map(fn(string $line) => strtolower(explode(':', $line, 2)[0]), Github::anonymous()->headers());
    assert_same(false, in_array('authorization', $names, true));
    assert_same(true, in_array('authorization', array_map(fn(string $line) => strtolower(explode(':', $line, 2)[0]), (new Github('t'))->headers()), true));
});

finish_tests();
