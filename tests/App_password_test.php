<?php

/**
 * Tests for App_password (the Database tab's Change password): which columns
 * hold passwords, reading a hash's settings, and making a new hash the same way.
 *
 * Run: php tests/App_password_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

define('APPPATH', dirname(__DIR__) . '/');

require_once APPPATH . 'modules/platform/live/App_password.php';

$column = fn(string $name, string $type = 'varchar', bool $editable = true) => ['name' => $name, 'type' => $type, 'editable' => $editable];

test('password-like text columns are password columns', function () use ($column) {
    foreach (['password', 'Password', 'passwd', 'pwd', 'pass', 'user_password', 'password_hash', 'pass_hash', 'admin_pwd', 'password_digest'] as $name) {
        assert_same([$name, true], [$name, App_password::is_password_column($column($name))]);
    }

    foreach (['passport', 'bypass', 'compass', 'password_reset_at', 'email'] as $name) {
        assert_same([$name, false], [$name, App_password::is_password_column($column($name))]);
    }
});

test('only editable text columns can hold a hash', function () use ($column) {
    assert_same(true, App_password::can_hold_hash($column('password', 'char')));
    assert_same(false, App_password::is_password_column($column('password', 'varbinary', false)));
    assert_same(false, App_password::is_password_column($column('password', 'int')));
    assert_same(false, App_password::is_password_column($column('password', 'varchar', false)));
});

test('reads bcrypt settings, including other libraries\' $2a$ and $2b$', function () {
    $hash = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 5]);
    assert_same(['algorithm' => 'bcrypt', 'options' => ['cost' => 5], 'prefix' => '$2y$'], App_password::settings($hash));
    assert_same('$2b$', App_password::settings('$2b$' . substr($hash, 4))['prefix']);
    assert_same(null, App_password::settings('$2x$' . substr($hash, 4)));
});

test('reads Argon2 settings', function () {
    $hash = password_hash('secret', PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 2, 'threads' => 1]);
    assert_same(['algorithm' => 'argon2id', 'options' => ['memory_cost' => 8192, 'time_cost' => 2, 'threads' => 1], 'prefix' => '$argon2id$'], App_password::settings($hash));
    assert_same('Argon2id, 8 MiB, 2 passes, 1 thread', App_password::describe_value($hash));
    assert_same('not a password hash', App_password::describe_value(md5('x')));
});

test('plain text, md5 and empty values are not hashes', function () {
    foreach ([null, '', 'hunter2', md5('x'), sha1('x'), '$1$abc$defghijklmnopqrstuv', '$P$Bxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'] as $value) {
        assert_same(false, App_password::is_hash($value));
    }
});

test('a recognised hash offers the same settings first', function () {
    $choices = App_password::choices(password_hash('secret', PASSWORD_BCRYPT, ['cost' => 11]));
    assert_same('same', $choices['default']);
    assert_same('Same as now: bcrypt, cost 11', $choices['choices']['same']);
    assert_same('bcrypt, cost 11', $choices['current']);
    assert_same(null, $choices['note']);
});

test('an empty cell defaults to bcrypt without a note', function () {
    $choices = App_password::choices(null);
    assert_same('bcrypt', $choices['default']);
    assert_same(false, isset($choices['choices']['same']));
    assert_same('empty', $choices['current']);
    assert_same(null, $choices['note']);
});

test('Argon2id is offered only where its hash fits', function () {
    assert_same(false, isset(App_password::choices(null, 'varchar(60)')['choices']['argon2id']));
    assert_same(true, isset(App_password::choices(null, 'varchar(255)')['choices']['argon2id']));
});

test('a value that is not a hash warns before replacing it', function () {
    $choices = App_password::choices(md5('x'));
    assert_same('bcrypt', $choices['default']);
    assert_same('not a password hash', $choices['current']);
    assert_same(true, str_contains((string) $choices['note'], 'not a password_hash() hash'));
});

test('settings too slow for the panel are not matched', function () {
    $slow_bcrypt = '$2y$15$' . str_repeat('a', 53);
    $choices = App_password::choices($slow_bcrypt);
    assert_same(false, isset($choices['choices']['same']));
    assert_same(true, str_contains((string) $choices['note'], 'too slow'));

    $big_argon = '$argon2id$v=19$m=1048576,t=4,p=1$c2FsdHNhbHQ$aGFzaGhhc2hoYXNo';
    assert_same(false, isset(App_password::choices($big_argon)['choices']['same']));
});

test('a new hash matches the current settings and verifies', function () {
    $current = password_hash('old', PASSWORD_BCRYPT, ['cost' => 6]);
    $hash = App_password::hash('n3w p4ss', $current, 'same');
    assert_same(true, password_verify('n3w p4ss', $hash));
    assert_same(['cost' => 6], App_password::settings($hash)['options']);

    $argon = password_hash('old', PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 2, 'threads' => 1]);
    $hash = App_password::hash('n3w', $argon, 'same');
    assert_same(true, password_verify('n3w', $hash));
    assert_same(App_password::settings($argon)['options'], App_password::settings($hash)['options']);
});

test('a $2b$ hash stays $2b$ and still verifies', function () {
    $current = '$2b$' . substr(password_hash('old', PASSWORD_BCRYPT, ['cost' => 5]), 4);
    $hash = App_password::hash('n3w', $current, 'same');
    assert_same('$2b$05$', substr($hash, 0, 7));
    assert_same(true, password_verify('n3w', $hash));
});

test('PHP defaults when picked, whatever is there now', function () {
    $hash = App_password::hash('n3w', password_hash('old', PASSWORD_BCRYPT, ['cost' => 5]), 'bcrypt');
    assert_same(['cost' => PASSWORD_BCRYPT_DEFAULT_COST], App_password::settings($hash)['options']);
    assert_same('argon2id', App_password::settings(App_password::hash('n3w', null, 'argon2id'))['algorithm']);
});

test('refuses choices that are not offered and passwords bcrypt would cut', function () {
    assert_throws(fn() => App_password::hash('n3w', null, 'same'), 'not available');
    assert_throws(fn() => App_password::hash('n3w', '$2y$15$' . str_repeat('a', 53), 'same'), 'not available');
    assert_throws(fn() => App_password::hash('n3w', null, 'md5'), 'not available');
    assert_throws(fn() => App_password::hash(str_repeat('x', 73), null, 'bcrypt'), '72 bytes');
    assert_throws(fn() => App_password::hash("a\0b", null, 'bcrypt'), 'NUL');
});

test('says when a hash does not fit the column', function () {
    $argon = App_password::hash('n3w', null, 'argon2id');
    assert_same(null, App_password::too_long(str_repeat('a', 60), 'varchar(60)'));
    assert_same(null, App_password::too_long($argon, 'varchar(255)'));
    assert_same(null, App_password::too_long($argon, 'text'));
    assert_same(true, str_contains((string) App_password::too_long($argon, 'char(60)'), 'holds 60'));
});

finish_tests();
