<?php

/**
 * Tests for Schema_diff (the Database tab's Schema view): reading schema.sql,
 * and what counts as a difference from the database.
 *
 * fixtures/schema_diff/schema.sql is a phpMyAdmin-style dump. The two JSON
 * files are App_database::schema_definition() of a MariaDB 10.11 database
 * that ran it, with explicit_defaults_for_timestamp on and off.
 *
 * Run: php tests/Schema_diff_test.php (exits non-zero on a failure)
 */

define('APPPATH', dirname(__DIR__) . '/');

require_once APPPATH . 'modules/platform/live/Schema_diff.php';

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

$fixtures = __DIR__ . '/fixtures/schema_diff/';
$sql = (string) file_get_contents($fixtures . 'schema.sql');

$live = function (string $file) use ($fixtures): array {
    return json_decode((string) file_get_contents($fixtures . $file), true, 512, JSON_THROW_ON_ERROR);
};

$diff = function (string $sql, array $live): array {
    $parsed = Schema_diff::parse($sql, $live['explicit_timestamps']);
    $database = Schema_diff::from_database($live['columns'], $live['keys'], $live['foreign_keys']);

    return Schema_diff::differences($parsed['tables'], $database);
};

$test('a dump matches the database it built', function () use ($same, $diff, $sql, $live) {
    $same([], $diff($sql, $live('database.json')));
});

$test('without explicit_defaults_for_timestamp too (bare TIMESTAMP columns)', function () use ($same, $diff, $sql, $live) {
    $same([], $diff($sql, $live('database-implicit-timestamps.json')));
});

$test('reads CREATE, ALTER (keys, MODIFY, AUTO_INCREMENT=) and DROP', function () use ($same, $sql) {
    $parsed = Schema_diff::parse($sql);
    $jobs = $parsed['tables']['jobs'];

    $same(['trongate_users', 'jobs', 'notes'], array_keys($parsed['tables']));
    $same(0, $parsed['skipped']);
    $same('int NOT NULL AUTO_INCREMENT', $jobs['columns']['id']['definition']);
    $same('decimal(10,2) NOT NULL DEFAULT 0', $jobs['columns']['salary']['definition']);
    $same("enum('Open','Closed') NOT NULL DEFAULT 'Open'", $jobs['columns']['status']['definition']);
    $same('longtext NULL', $jobs['columns']['meta']['definition']);
    $same(['PRIMARY KEY (id)', 'KEY (title(20))'], array_keys($jobs['keys']));
    $same('(trongate_user_id) REFERENCES trongate_users (id) ON DELETE CASCADE', $jobs['foreign_keys']['(trongate_user_id) REFERENCES trongate_users (id)']['definition']);
});

$test('the platform schema parses', function () use ($same) {
    $parsed = Schema_diff::parse((string) file_get_contents(APPPATH . 'db/schema.sql'));

    $same(0, $parsed['skipped']);
    $same(true, isset($parsed['tables']['app_runtime']['columns']['health_check_path']));
    $same("varchar(200) NOT NULL DEFAULT ''", $parsed['tables']['app_runtime']['columns']['health_check_path']['definition']);
});

$test('each difference has the schema.sql side and the database side', function () use ($same, $diff, $sql, $live) {
    $database = $live('database.json');
    $columns = [];

    foreach ($database['columns'] as $column) {
        if ($column['table'] === 'jobs' && $column['name'] === 'remote') {
            continue;
        }

        if ($column['table'] === 'trongate_users' && $column['name'] === 'code') {
            $column['column_type'] = 'varchar(64)';
        }

        $columns[] = $column;
    }

    $columns[] = ['table' => 'notes', 'name' => 'pinned', 'column_type' => 'tinyint(1)', 'nullable' => false, 'default' => '0', 'extra' => ''];
    $columns[] = ['table' => 'sessions', 'name' => 'id', 'column_type' => 'varchar(128)', 'nullable' => false, 'default' => null, 'extra' => ''];
    $database['columns'] = $columns;

    $same([
        ['table' => 'jobs', 'kind' => 'Column', 'name' => 'remote', 'schema' => 'tinyint NOT NULL DEFAULT 0', 'database' => null],
        ['table' => 'notes', 'kind' => 'Column', 'name' => 'pinned', 'schema' => null, 'database' => 'tinyint NOT NULL DEFAULT 0'],
        ['table' => 'sessions', 'kind' => 'Table', 'name' => 'sessions', 'schema' => null, 'database' => '1 column: id'],
        ['table' => 'trongate_users', 'kind' => 'Column', 'name' => 'code', 'schema' => 'varchar(32) NULL', 'database' => 'varchar(64) NULL'],
    ], $diff($sql, $database));
});

$test('keys and foreign keys compare by what they cover, not their names', function () use ($same, $diff, $sql, $live) {
    $database = $live('database.json');
    $keys = [];

    foreach ($database['keys'] as $key) {
        if ($key['name'] === 'title_prefix') {
            continue;
        }

        if ($key['name'] === 'code') {
            $key['name'] = 'unique_code';
        }

        $keys[] = $key;
    }

    $database['keys'] = $keys;
    $database['foreign_keys'] = [];

    // MariaDB's own index for the dropped foreign key stays behind; it is not a difference
    $same([
        ['table' => 'jobs', 'kind' => 'Key', 'name' => 'title_prefix', 'schema' => 'KEY (title(20))', 'database' => null],
        ['table' => 'jobs', 'kind' => 'Foreign key', 'name' => 'fk_jobs_user', 'schema' => '(trongate_user_id) REFERENCES trongate_users (id) ON DELETE CASCADE', 'database' => null],
    ], $diff($sql, $database));
});

$test('a table only schema.sql has', function () use ($same, $diff, $sql, $live) {
    $database = $live('database.json');
    $database['columns'] = array_values(array_filter($database['columns'], fn($column) => $column['table'] !== 'notes'));
    $database['keys'] = array_values(array_filter($database['keys'], fn($key) => $key['table'] !== 'notes'));

    $same([
        ['table' => 'notes', 'kind' => 'Table', 'name' => 'notes', 'schema' => '4 columns: id, job_id, body, created_at', 'database' => null],
    ], $diff($sql, $database));
});

$test('types as MariaDB writes them', function () use ($same) {
    $same('int', Schema_diff::normalize_type('INT(11)'));
    $same('int unsigned', Schema_diff::normalize_type('int(10) UNSIGNED'));
    $same('tinyint', Schema_diff::normalize_type('BOOLEAN'));
    $same('decimal(10,0)', Schema_diff::normalize_type('NUMERIC'));
    $same('decimal(8,2)', Schema_diff::normalize_type('DECIMAL(8, 2)'));
    $same('double', Schema_diff::normalize_type('double precision'));
    $same("enum('a','B c')", Schema_diff::normalize_type("ENUM('a', 'B c')"));
    $same('varchar(20)', Schema_diff::normalize_type('VARCHAR(20)'));
});

$test('ALTER changes it cannot follow are counted, not guessed', function () use ($same) {
    $parsed = Schema_diff::parse("CREATE TABLE t (id INT, name VARCHAR(10));\nALTER TABLE t ALTER name SET DEFAULT 'x', ADD INDEX (name);");

    $same(1, $parsed['skipped']);
    $same(['KEY (name)'], array_keys($parsed['tables']['t']['keys']));
});

$test('CHANGE renames a column and DROP removes one', function () use ($same) {
    $parsed = Schema_diff::parse("CREATE TABLE t (id INT, name VARCHAR(10), gone INT);\nALTER TABLE t CHANGE COLUMN name title VARCHAR(20) NOT NULL, DROP COLUMN gone;");

    $same(['id', 'title'], array_keys($parsed['tables']['t']['columns']));
    $same('varchar(20) NOT NULL', $parsed['tables']['t']['columns']['title']['definition']);
});

exit($failures === 0 ? 0 : 1);
