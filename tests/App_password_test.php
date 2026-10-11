<?php

/**
 * Tests for App_password (the Database tab's Change password): which columns
 * hold passwords, reading a hash's settings, and making a new hash the same way.
 *
 * Run: php tests/App_password_test.php (exits non-zero on a failure)
 */

define('APPPATH', dirname(__DIR__) . '/');

require_once APPPATH . 'modules/platform/live/App_password.php';

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

$throws = function (callable $body, string $needle): void {
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
};

$column = fn(string $name, string $type = 'varchar', bool $editable = true) => ['name' => $name, 'type' => $type, 'editable' => $editable];

$test('password-like text columns are password columns', function () use ($same, $column) {
    foreach (['password', 'Password', 'passwd', 'pwd', 'pass', 'user_password', 'password_hash', 'pass_hash', 'admin_pwd', 'password_digest'] as $name) {
        $same([$name, true], [$name, App_password::is_password_column($column($name))]);
    }

    foreach (['passport', 'bypass', 'compass', 'password_reset_at', 'email'] as $name) {
        $same([$name, false], [$name, App_password::is_password_column($column($name))]);
    }
});

$test('only editable text columns can hold a hash', function () use ($same, $column) {
    $same(true, App_password::can_hold_hash($column('password', 'char')));
    $same(false, App_password::is_password_column($column('password', 'varbinary', false)));
    $same(false, App_password::is_password_column($column('password', 'int')));
    $same(false, App_password::is_password_column($column('password', 'varchar', false)));
});

$test('reads bcrypt settings, including other libraries\' $2a$ and $2b$', function () use ($same) {
    $hash = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 5]);
    $same(['algorithm' => 'bcrypt', 'options' => ['cost' => 5], 'prefix' => '$2y$'], App_password::settings($hash));
    $same('$2b$', App_password::settings('$2b$' . substr($hash, 4))['prefix']);
    $same(null, App_password::settings('$2x$' . substr($hash, 4)));
});

$test('reads Argon2 settings', function () use ($same) {
    $hash = password_hash('secret', PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 2, 'threads' => 1]);
    $same(['algorithm' => 'argon2id', 'options' => ['memory_cost' => 8192, 'time_cost' => 2, 'threads' => 1], 'prefix' => '$argon2id$'], App_password::settings($hash));
    $same('Argon2id, 8 MiB, 2 passes, 1 thread', App_password::describe_hash($hash));
    $same('not a password hash', App_password::describe_hash(md5('x')));
});

$test('plain text, md5 and empty values are not hashes', function () use ($same) {
    foreach ([null, '', 'hunter2', md5('x'), sha1('x'), '$1$abc$defghijklmnopqrstuv', '$P$Bxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'] as $value) {
        $same(false, App_password::is_hash($value));
    }
});

$test('a recognised hash offers the same settings first', function () use ($same) {
    $choices = App_password::choices(password_hash('secret', PASSWORD_BCRYPT, ['cost' => 11]));
    $same('same', $choices['default']);
    $same('Same as now: bcrypt, cost 11', $choices['choices']['same']);
    $same('bcrypt, cost 11', $choices['current']);
    $same(null, $choices['note']);
});

$test('an empty cell defaults to bcrypt without a note', function () use ($same) {
    $choices = App_password::choices(null);
    $same('bcrypt', $choices['default']);
    $same(false, isset($choices['choices']['same']));
    $same('empty', $choices['current']);
    $same(null, $choices['note']);
});

$test('Argon2id is offered only where its hash fits', function () use ($same) {
    $same(false, isset(App_password::choices(null, 'varchar(60)')['choices']['argon2id']));
    $same(true, isset(App_password::choices(null, 'varchar(255)')['choices']['argon2id']));
});

$test('a value that is not a hash warns before replacing it', function () use ($same) {
    $choices = App_password::choices(md5('x'));
    $same('bcrypt', $choices['default']);
    $same('not a password hash', $choices['current']);
    $same(true, str_contains((string) $choices['note'], 'not a password_hash() hash'));
});

$test('settings too slow for the panel are not matched', function () use ($same) {
    $slow_bcrypt = '$2y$15$' . str_repeat('a', 53);
    $choices = App_password::choices($slow_bcrypt);
    $same(false, isset($choices['choices']['same']));
    $same(true, str_contains((string) $choices['note'], 'too slow'));

    $big_argon = '$argon2id$v=19$m=1048576,t=4,p=1$c2FsdHNhbHQ$aGFzaGhhc2hoYXNo';
    $same(false, isset(App_password::choices($big_argon)['choices']['same']));
});

$test('a new hash matches the current settings and verifies', function () use ($same) {
    $current = password_hash('old', PASSWORD_BCRYPT, ['cost' => 6]);
    $hash = App_password::hash('n3w p4ss', $current, 'same');
    $same(true, password_verify('n3w p4ss', $hash));
    $same(['cost' => 6], App_password::settings($hash)['options']);

    $argon = password_hash('old', PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 2, 'threads' => 1]);
    $hash = App_password::hash('n3w', $argon, 'same');
    $same(true, password_verify('n3w', $hash));
    $same(App_password::settings($argon)['options'], App_password::settings($hash)['options']);
});

$test('a $2b$ hash stays $2b$ and still verifies', function () use ($same) {
    $current = '$2b$' . substr(password_hash('old', PASSWORD_BCRYPT, ['cost' => 5]), 4);
    $hash = App_password::hash('n3w', $current, 'same');
    $same('$2b$05$', substr($hash, 0, 7));
    $same(true, password_verify('n3w', $hash));
});

$test('PHP defaults when picked, whatever is there now', function () use ($same) {
    $hash = App_password::hash('n3w', password_hash('old', PASSWORD_BCRYPT, ['cost' => 5]), 'bcrypt');
    $same(['cost' => PASSWORD_BCRYPT_DEFAULT_COST], App_password::settings($hash)['options']);
    $same('argon2id', App_password::settings(App_password::hash('n3w', null, 'argon2id'))['algorithm']);
});

$test('refuses choices that are not offered and passwords bcrypt would cut', function () use ($throws) {
    $throws(fn() => App_password::hash('n3w', null, 'same'), 'not available');
    $throws(fn() => App_password::hash('n3w', '$2y$15$' . str_repeat('a', 53), 'same'), 'not available');
    $throws(fn() => App_password::hash('n3w', null, 'md5'), 'not available');
    $throws(fn() => App_password::hash(str_repeat('x', 73), null, 'bcrypt'), '72 bytes');
    $throws(fn() => App_password::hash("a\0b", null, 'bcrypt'), 'NUL');
});

$test('says when a hash does not fit the column', function () use ($same) {
    $argon = App_password::hash('n3w', null, 'argon2id');
    $same(null, App_password::too_long(str_repeat('a', 60), 'varchar(60)'));
    $same(null, App_password::too_long($argon, 'varchar(255)'));
    $same(null, App_password::too_long($argon, 'text'));
    $same(true, str_contains((string) App_password::too_long($argon, 'char(60)'), 'holds 60'));
});

exit($failures === 0 ? 0 : 1);
