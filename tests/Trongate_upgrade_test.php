<?php

/**
 * Tests for Trongate_upgrade and Upgrade_plan, which plan the pull request
 * moving an app to a newer Trongate release (tasks/upgrade_app.php).
 *
 * Run: php tests/Trongate_upgrade_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/../modules/platform/upgrade/Trongate_upgrade.php';

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

/**
 * A plan from three file sets (path => content), the way the task builds it
 * from GitHub trees and blobs
 *
 * @param array<string, string> $base
 * @param array<string, string> $target
 * @param array<string, string> $app
 */
$plan = function (array $base, array $target, array $app): Upgrade_plan {
    $files = ['base' => $base, 'target' => $target, 'app' => $app];
    $tree = fn(array $set) => array_map([Trongate_upgrade::class, 'blob_sha'], $set);

    return (new Trongate_upgrade($tree($base), $tree($target), $tree($app), fn(string $side, string $path) => $files[$side][$path]))->plan();
};

$lines = fn(string ...$lines) => implode("\n", $lines) . "\n";

$test('releases keeps v2 tags, newest first', function () use ($same) {
    $same(
        ['2.2026.0901' => 'v2.2026.0901', '2.2026.0314' => '2.2026.0314'],
        Trongate_upgrade::releases(['1.4.2', '2.2026.0314', 'v2.2026.0901', 'nightly', 'v3.2027.0101']),
    );
});

$test('blob_sha matches git', function () use ($same) {
    // git hash-object of "hello\n"
    $same('ce013625030ba8dba906f756967f9e9ca394464a', Trongate_upgrade::blob_sha("hello\n"));
});

$test('closest finds the release an app is on, even with a changed engine file', function () use ($same) {
    $sha = fn(string $content) => Trongate_upgrade::blob_sha($content);
    $releases = [
        '2.2026.0901' => ['engine/A.php' => $sha('a2'), 'engine/B.php' => $sha('b2'), 'engine/C.php' => $sha('c1')],
        '2.2026.0314' => ['engine/A.php' => $sha('a1'), 'engine/B.php' => $sha('b1'), 'engine/C.php' => $sha('c1')],
    ];
    $app = ['engine/A.php' => $sha('a1'), 'engine/B.php' => $sha('changed'), 'engine/C.php' => $sha('c1'), 'modules/shop/Shop.php' => $sha('x')];

    $same('2.2026.0314', Trongate_upgrade::closest($app, $releases));
    $same(null, Trongate_upgrade::closest(['engine/A.php' => $sha('mine')], $releases));
});

$test('untouched files take the newer copy; files only the app changed stay', function () use ($plan, $same) {
    $result = $plan(
        ['engine/A.php' => 'a1', 'engine/B.php' => 'b1', 'engine/Gone.php' => 'g'],
        ['engine/A.php' => 'a2', 'engine/B.php' => 'b1', 'engine/New.php' => 'n'],
        ['engine/A.php' => 'a1', 'engine/B.php' => 'mine', 'engine/Gone.php' => 'g', 'modules/shop/Shop.php' => 'x'],
    );

    $same(['engine/A.php' => 'a2', 'engine/Gone.php' => null, 'engine/New.php' => 'n'], $result->changes);
    $same(['engine/New.php'], $result->files['added']);
    $same(['engine/Gone.php'], $result->files['deleted']);
});

$test('changes on both sides merge, overlapping ones keep conflict markers', function () use ($plan, $same, $lines) {
    $base = $lines('one', 'two', 'three', 'four', 'five');
    $result = $plan(
        ['engine/M.php' => $base, 'engine/C.php' => $base],
        ['engine/M.php' => $lines('ONE', 'two', 'three', 'four', 'five'), 'engine/C.php' => $lines('one', 'two', 'THEIRS', 'four', 'five')],
        ['engine/M.php' => $lines('one', 'two', 'three', 'four', 'FIVE'), 'engine/C.php' => $lines('one', 'two', 'YOURS', 'four', 'five')],
    );

    $same($lines('ONE', 'two', 'three', 'four', 'FIVE'), $result->changes['engine/M.php']);
    $same(['engine/M.php'], $result->files['merged']);
    $same(['engine/C.php'], $result->files['conflicts']);
    $conflict = $result->changes['engine/C.php'];

    if (!str_contains($conflict, "<<<<<<< yours\nYOURS\n") || !str_contains($conflict, "THEIRS\n>>>>>>> newer Trongate")) {
        throw new RuntimeException("No conflict markers:\n{$conflict}");
    }
});

$test('a file the app changed and the release deleted stays, and so does one the app deleted', function () use ($plan, $same) {
    $result = $plan(
        ['engine/Old.php' => 'o', 'modules/db/Db.php' => 'd1'],
        ['modules/db/Db.php' => 'd2'],
        ['engine/Old.php' => 'mine'],
    );

    $same([], $result->changes);
    $same(['engine/Old.php', 'modules/db/Db.php'], array_keys($result->kept));
});

$test('docs at the root are left alone', function () use ($plan, $same) {
    $result = $plan(['README.md' => 'r1', 'license.txt' => 'l1'], ['README.md' => 'r2', 'license.txt' => 'l2'], ['README.md' => 'r1', 'license.txt' => 'l1']);

    $same([], $result->changes);
});

$test('config files get the new settings and keep the app values', function () use ($plan, $same) {
    $base = "<?php\ndefine('BASE_URL', 'http://localhost/');\n\$config['email']['host'] = 'localhost';\n";
    $target = "<?php\ndefine('BASE_URL', 'http://localhost/');\ndefine('INTERCEPT', true);\ndefine('LOGS', __DIR__ . '/logs');\n\$config['email']['host'] = 'localhost';\n\$config['email']['port'] = 25;\n";
    $app = "<?php\ndefine('BASE_URL', 'https://shop.example/');\n\$config['email']['host'] = 'mail.example';\n";

    $result = $plan(['config/config.php' => $base], ['config/config.php' => $target], ['config/config.php' => $app]);
    $out = $result->changes['config/config.php'];

    $same(['config/config.php' => ['INTERCEPT', 'config.email.port']], $result->settings);
    $same(['config/config.php' => ['LOGS']], $result->manual_settings);
    $settings = Config_editor::settings('config.php', $out);
    $same('https://shop.example/', $settings['BASE_URL']['value']);
    $same('mail.example', $settings['config.email.host']['value']);
    $same(true, $settings['INTERCEPT']['value']);
    $same(25, $settings['config.email.port']['value']);
});

$test('a constant the app defines in another config file is not added again', function () use ($plan, $same) {
    $result = $plan(
        ['config/config.php' => "<?php\n"],
        ['config/config.php' => "<?php\ndefine('DEFAULT_MODULE', 'welcome');\n"],
        ['config/config.php' => "<?php\n", 'config/site.php' => "<?php\ndefine('DEFAULT_MODULE', 'shop');\n"],
    );

    $same([], $result->changes);
});

$test('a config file new in the release is added; one the app deleted is not', function () use ($plan, $same) {
    $result = $plan(
        ['config/old.php' => "<?php\n"],
        ['config/old.php' => "<?php\ndefine('A', 1);\n", 'config/trongate_email.php' => "<?php\n"],
        [],
    );

    $same(['config/trongate_email.php' => "<?php\n"], $result->changes);
});

$test('the description lists conflicts and SQL to import', function () use ($plan, $same, $lines) {
    $result = $plan(
        ['engine/C.php' => $lines('a', 'b')],
        ['engine/C.php' => $lines('a', 'theirs'), 'modules/login/login.sql' => 'CREATE TABLE x (id INT);'],
        ['engine/C.php' => $lines('a', 'yours')],
    );
    $text = $result->description('2.2026.0314', '2.2026.0901', Trongate_upgrade::SOURCE);

    $same(['modules/login/login.sql'], $result->sql_files());
    $same(1, $result->counts()['conflicts']);

    foreach (['### Resolve before merging', '`engine/C.php`', '### Import into your database', '`modules/login/login.sql`', 'not affiliated with or endorsed by'] as $expected) {
        if (!str_contains($text, $expected)) {
            throw new RuntimeException("Missing '{$expected}' in:\n{$text}");
        }
    }
});

echo $failures === 0 ? "\nAll tests passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
