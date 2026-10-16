<?php

/**
 * Tests for Deploy_steps, the timeline on a deployment page: step states
 * from the worker's 'step' log lines, log lines grouped under their steps,
 * and config rows that name exactly what Trongate_config::rules() sets
 * without showing a secret.
 *
 * Run: php tests/Deploy_steps_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

putenv('APP_KEY=test-key-for-deploy-steps');
putenv('MAIL_KEY=test-mail-key');

require_once __DIR__ . '/../modules/platform/manifests/Manifests.php';
require_once __DIR__ . '/../modules/deployments/Deploy_steps.php';

$database = ['host' => 'shared-1.databases', 'port' => '3306', 'user' => 'p7', 'password' => 'secret-db-password', 'database' => 'p7'];

$app = function (array $settings = []): array {
    return $settings + ['id' => 7, 'slug' => 'shop', 'type' => 'trongate_v2', 'trongate_env' => 'prod', 'default_module' => '', 'captures_mail' => 1];
};

/** Keys Trongate_config::rules() sets for the app, sorted */
$rule_keys = function (array $app) use ($database): array {
    $keys = array_map(fn(Config_rule $rule) => $rule->key, Trongate_config::rules($app, $database));
    sort($keys);

    return $keys;
};

/** Keys the config rows name, sorted */
$row_keys = function (array $app): array {
    $keys = array_merge(...array_column(Deploy_steps::config_rows($app), 'keys'));
    sort($keys);

    return $keys;
};

$apps = [
    'v2 with mail capture' => $app(),
    'v2 without mail capture' => $app(['captures_mail' => 0]),
    'v2 with a default module' => $app(['default_module' => 'welcome']),
    'v1' => $app(['type' => 'trongate_v1']),
];

foreach ($apps as $name => $settings) {
    test("config rows name what the platform sets: {$name}", function () use ($settings, $rule_keys, $row_keys) {
        assert_same($rule_keys($settings), $row_keys($settings));
    });
}

test('config rows show the values, never the secrets', function () use ($app) {
    $rows = Deploy_steps::config_rows($app(['default_module' => 'welcome']));
    $shown = json_encode(array_map(fn(array $row) => [$row['file'], $row['setting'], $row['value']], $rows));

    assert_contains('https:\/\/shop.', $shown);
    assert_contains('"prod"', $shown);
    assert_contains('"welcome"', $shown);
    assert_same(false, str_contains($shown, Mail_capture::password(7)));
    assert_same(false, str_contains($shown, 'secret-db-password'));
});

/** 'step' log lines for the given keys, one second apart */
$marks = function (string ...$keys): array {
    $marks = [];

    foreach ($keys as $i => $key) {
        $marks[] = (object) ['id' => ($i + 1) * 10, 'message' => $key, 'created_at' => '2026-10-16 22:00:0' . $i];
    }

    return $marks;
};

$states = function (string $status, array $marks) use ($app): array {
    return array_column(Deploy_steps::steps($app(), ['status' => $status], $marks), 'state', 'key');
};

test('a running deployment is on its latest step', function () use ($states, $marks) {
    assert_same(
        ['source' => 'waiting', 'build' => 'waiting', 'config' => 'waiting', 'start' => 'waiting', 'check' => 'waiting', 'restore' => 'hidden'],
        $states('pending', []),
    );
    assert_same(
        ['source' => 'done', 'build' => 'current', 'config' => 'waiting', 'start' => 'waiting', 'check' => 'waiting', 'restore' => 'hidden'],
        $states('building', $marks('source', 'build')),
    );
});

test('a successful deployment has every step done', function () use ($states, $marks) {
    assert_same(
        ['source' => 'done', 'build' => 'done', 'config' => 'done', 'start' => 'done', 'check' => 'done', 'restore' => 'hidden'],
        $states('success', $marks('source', 'build', 'config', 'start', 'check')),
    );
});

test('a failed deployment fails on its latest step', function () use ($states, $marks) {
    assert_same(
        ['source' => 'done', 'build' => 'failed', 'config' => 'waiting', 'start' => 'waiting', 'check' => 'waiting', 'restore' => 'hidden'],
        $states('failed', $marks('source', 'build')),
    );
});

test('the step before a restore failed, and the restore shows', function () use ($states, $marks) {
    assert_same(
        ['source' => 'done', 'build' => 'done', 'config' => 'done', 'start' => 'failed', 'check' => 'waiting', 'restore' => 'done'],
        $states('failed', $marks('source', 'build', 'config', 'start', 'restore')),
    );
});

test('a rollback skips reading and building', function () use ($states, $marks) {
    assert_same(
        ['source' => 'skipped', 'build' => 'skipped', 'config' => 'done', 'start' => 'done', 'check' => 'done', 'restore' => 'hidden'],
        $states('success', $marks('config', 'start', 'check')),
    );
});

test('a deployment from before steps were logged has no states and unsorted logs', function () use ($states) {
    assert_same(['source' => '', 'build' => '', 'config' => '', 'start' => '', 'check' => '', 'restore' => 'hidden'], $states('success', []));
    assert_same(true, Deploy_steps::is_unsorted(['status' => 'success'], []));
    assert_same(false, Deploy_steps::is_unsorted(['status' => 'building'], []));
});

test('log lines go under the step they were written in', function () use ($marks) {
    $line = fn(int $id, string $level = 'info') => (object) ['id' => $id, 'level' => $level, 'message' => "line {$id}", 'created_at' => '2026-10-16 22:00:00'];
    $logs = [$line(5), $line(10, 'step'), $line(11), $line(20, 'step'), $line(21), $line(22)];
    $ids = array_map(fn(array $lines) => array_map(fn(object $l) => $l->id, $lines), Deploy_steps::group_logs($logs, $marks('source', 'build')));

    assert_same(['source' => [5, 11], 'build' => [21, 22], 'config' => [], 'start' => [], 'check' => [], 'restore' => []], $ids);
});

test('now names the current step', function () use ($app, $marks) {
    assert_same('Now: 2. Build the image', Deploy_steps::now(Deploy_steps::steps($app(), ['status' => 'building'], $marks('source', 'build'))));
    assert_same('', Deploy_steps::now(Deploy_steps::steps($app(), ['status' => 'success'], $marks('source'))));
});

test('the kept config files and persistent paths are listed', function () use ($app) {
    $steps = Deploy_steps::steps($app(['persistent_paths' => '["public/uploads"]']), [
        'status' => 'success',
        'config_files' => json_encode(['config.php' => '<?php', 'database.php' => '<?php']),
    ], []);
    $text = implode("\n", array_merge(...array_column($steps, 'text')));

    assert_contains('config/config.php, config/database.php', $text);
    assert_contains('public/uploads', $text);
});

finish_tests();
