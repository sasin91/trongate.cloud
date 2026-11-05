<?php

/**
 * Every table in db/schema.sql has a tg-admin page in the admin menu, and no
 * page selects or shows a password, token, secret or stored message body.
 *
 * Run: php tests/Admin_pages_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

$root = __DIR__ . '/..';

preg_match_all('/^CREATE TABLE (\w+) \(/m', file_get_contents("$root/db/schema.sql"), $m);
$tables = $m[1];

// Columns no admin page selects or shows
$hidden = [
    'trongate_users' => ['code'],
    'trongate_tokens' => ['token'],
    'password_resets' => ['token'],
    'customer' => ['password'],
    'secrets' => ['variables'],
    'mcp_oauth_client' => ['client_secret_hash'],
    'mcp_oauth_token' => ['token_hash', 'code_challenge'],
    'app_git_token' => ['token_hash'],
    'app_mail' => ['raw'],
    'app_database_backup' => ['manifest', 'data'],
    'deployment' => ['config_files'],
];

// Each table's admin module: the model whose $table_name it is
$modules = [];
foreach (glob("$root/modules/{*,*/*}/*_model.php", GLOB_BRACE) as $model) {
    if (preg_match("/private string \\\$table_name = '(\w+)';/", file_get_contents($model), $t)) {
        $modules[$t[1]] = dirname($model);
    }
}
$modules['trongate_administrators'] = "$root/modules/trongate_administrators";

$admin = file_get_contents("$root/modules/templates/views/admin.php");
foreach ($tables as $table) {
    if (!isset($modules[$table])) {
        fail("$table has no admin module");
        continue;
    }
    $dir = $modules[$table];
    if (!is_file("$dir/views/manage.php")) {
        fail("$table has no manage page");
    }
    $url = str_replace('/', '-', substr($dir, strlen("$root/modules/"))) . '/manage';
    if (!str_contains($admin, "'$url'")) {
        fail("$table ($url) isn't in the admin menu");
    }
    if (!isset($hidden[$table])) {
        continue;
    }
    $model = file_get_contents(glob("$dir/*_model.php")[0]);
    if (str_contains($model, 'SELECT *') || str_contains($model, 'get_where(')) {
        fail("$table: the model selects every column");
    }
    $columns = implode('|', $hidden[$table]);
    if (preg_match('/`(' . $columns . ')`/', $model, $s)) {
        fail("$table: the model selects $s[1]");
    }
    foreach (glob("$dir/views/*.php") as $view) {
        if (preg_match('/\$(?:row->)?(' . $columns . ')\b/', file_get_contents($view), $s)) {
            fail("$table: " . basename($view) . " shows $s[1]");
        }
    }
}

echo count($tables), " tables checked\n";
finish_tests();
