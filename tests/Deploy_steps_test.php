<?php

/**
 * Tests for Deploy_steps, the "What happens" card on a deployment page: its
 * config rows must name exactly what Trongate_config::rules() sets, and
 * never show a secret.
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

test('steps follow the deployment status', function () use ($app) {
    $states = fn(string $status) => array_column(Deploy_steps::steps($app(), ['status' => $status, 'config_files' => null]), 'state');

    assert_same(['waiting', 'waiting', 'waiting', 'waiting', 'waiting', ''], $states('pending'));
    assert_same(['current', 'current', 'waiting', 'waiting', 'waiting', ''], $states('building'));
    assert_same(['done', 'done', 'current', 'current', 'current', ''], $states('deploying'));
    assert_same(['done', 'done', 'done', 'done', 'done', ''], $states('success'));
    // Where a failed deploy stopped is not known: no step claims it
    assert_same(['', '', '', '', '', ''], $states('failed'));
});

test('now names the current steps', function () use ($app) {
    assert_same('Now: steps 1 to 2', Deploy_steps::now(Deploy_steps::steps($app(), ['status' => 'building'])));
    assert_same('Now: steps 3 to 5', Deploy_steps::now(Deploy_steps::steps($app(), ['status' => 'deploying'])));
    assert_same('Now: 1. Read your repository', Deploy_steps::now([['title' => 'Read your repository', 'state' => 'current'], ['title' => 'Build', 'state' => 'waiting']]));
    assert_same('', Deploy_steps::now(Deploy_steps::steps($app(), ['status' => 'success'])));
});

test('the kept config files and persistent paths are listed', function () use ($app) {
    $steps = Deploy_steps::steps($app(['persistent_paths' => '["public/uploads"]']), [
        'status' => 'success',
        'config_files' => json_encode(['config.php' => '<?php', 'database.php' => '<?php']),
    ]);
    $text = implode("\n", array_merge(...array_column($steps, 'text')));

    assert_contains('config/config.php, config/database.php', $text);
    assert_contains('public/uploads', $text);
});

finish_tests();
