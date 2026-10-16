<?php

/**
 * Tests for Sql_check, the live check of SQL while it is typed, and the
 * syntax checks it shares with the real submit (App_database::syntax_refusal).
 *
 * Run: php tests/Sql_check_test.php (exits non-zero on a failure)
 */

require_once dirname(__DIR__) . '/modules/platform/live/Sql_check.php';

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

$test('quotes, comments and brackets that pair up pass', function () use ($same) {
    $same(null, App_database::syntax_refusal("INSERT INTO t (a, b) VALUES ('it''s (', \"x)\"), (`c)`, 'a\\'b') -- (\n"));
    $same(null, App_database::syntax_refusal("SELECT /* ( */ 1 # )\n"));
});

$test('quotes, comments and brackets left open are refused', function () use ($same) {
    $same("A ' is never closed.", App_database::syntax_refusal("INSERT INTO t VALUES ('a)"));
    $same('A ` is never closed.', App_database::syntax_refusal('SELECT `a FROM t'));
    $same('A /* comment is never closed.', App_database::syntax_refusal('SELECT 1 /* oops'));
    $same('A ( is never closed.', App_database::syntax_refusal('CREATE TABLE t (id INT'));
    $same('2 ( are never closed.', App_database::syntax_refusal('SELECT ((1'));
    $same('A ) has no ( before it.', App_database::syntax_refusal('SELECT 1)'));
});

$test('the real submit refuses what the check finds', function () use ($same) {
    $same('Statement 2: A ( is never closed.', App_database::refusal("SELECT 1;\nCREATE TABLE t (id INT", true));
    $same(null, App_database::refusal("SELECT 1;\nCREATE TABLE t (id INT)", true));
    $same('Statement 1: A ( is never closed.', Setup_sql_plan::plan('CREATE TABLE t (id INT; SELECT 1')['refusal']);
});

$test('Database tab: each refused statement with its line', function () use ($same) {
    $sql = "SELECT 1;\n\nUPDATE t SET a = 1;\nSET max_statement_time = 0;\nSELECT (2";
    $check = Sql_check::database_tab($sql, false);

    $same(4, $check['statements']);
    $same(App_database::refusal($sql, false), $check['refusal']);
    $same([2, 3, 4], array_column($check['problems'], 'number'));
    $same([3, 4, 5], array_column($check['problems'], 'line'));
    $same('UPDATE t SET a = 1', $check['problems'][0]['preview']);
    $same('This changes your database. Tick "Allow changes" to run it.', $check['problems'][0]['message']);
    $same('4 statements, 3 would be refused.', Sql_check::summary($check));
});

$test('Database tab: allowed SQL has nothing to show but its count', function () use ($same) {
    $check = Sql_check::database_tab("SELECT 1; UPDATE t SET a = 1", true);

    $same(null, $check['refusal']);
    $same([], $check['problems']);
    $same('2 statements, nothing refused. MariaDB still checks names and spelling when it runs.', Sql_check::summary($check));
});

$test('Database tab: too long is refused as a whole', function () use ($same) {
    $check = Sql_check::database_tab(str_repeat('SELECT 1;', 10000), false);

    $same([], $check['problems']);
    $same('That SQL is longer than 64 KB.', Sql_check::summary($check));
});

$test('Database tab: an executable comment is refused on its statement', function () use ($same) {
    $check = Sql_check::database_tab("SELECT 1;\nSELECT /*! SLEEP(1) */ 2", false);

    $same([2], array_column($check['problems'], 'number'));
    $same('Executable comments (/*! ... */) are not allowed here.', $check['problems'][0]['message']);
});

$test('setup: dump transactions are skipped, SET checked as the wizard does', function () use ($same) {
    $sql = "START TRANSACTION;\nSET NAMES utf8mb4;\nSET max_statement_time = 0;\nCREATE TABLE t (id INT);\nCOMMIT;";
    $check = Sql_check::setup($sql);

    $same(3, $check['statements']);
    $same(2, $check['skipped']);
    $same(Setup_sql_plan::plan($sql)['refusal'], $check['refusal']);
    $same([3], array_column($check['problems'], 'number'));
    $same('3 statements, 2 transaction or lock statements skipped, 1 would be refused.', Sql_check::summary($check));
});

$test('only the first problems are listed, then how many more', function () use ($same) {
    $check = Sql_check::database_tab(str_repeat("DROP TABLE t;\n", 25), false);

    $same(Sql_check::MAX_PROBLEMS, count($check['problems']));
    $same(5, $check['more_problems']);
});

$test('long statements are cut for the preview', function () use ($same) {
    $preview = Sql_check::preview("-- note\nSELECT\n  " . str_repeat('a, ', 50) . 'b');

    $same(true, mb_strlen($preview) <= Sql_check::PREVIEW_CHARS);
    $same('…', mb_substr($preview, -1));
    $same('SELECT a, a,', substr($preview, 0, 12));
});

exit($failures > 0 ? 1 : 0);
