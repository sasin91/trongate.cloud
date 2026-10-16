<?php

/**
 * Tests for Sql_checker, the live check of SQL while it is typed, and the
 * syntax checks it shares with the real submit (App_database::syntax_refusal).
 *
 * Run: php tests/Sql_checker_test.php (exits non-zero on a failure)
 */

require_once dirname(__DIR__) . '/modules/platform/live/Sql_checker.php';
require_once dirname(__DIR__) . '/modules/platform/live/Setup_sql_rules.php';

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
    $same(['message' => 'A ( is never closed.', 'label' => 'opened here, never closed', 'offset' => 8], App_database::syntax_problem('SELECT ((1'));
    $same('A ) has no ( before it.', App_database::syntax_refusal('SELECT 1)'));
});

$test('a rule set refuses the same in its real submit and its plan', function () use ($same) {
    $rules = new Setup_sql_rules();
    $plan = $rules->plan("START TRANSACTION;\nCREATE TABLE t (id INT);\nCOMMIT;");

    $same(['CREATE TABLE t (id INT)'], $plan['statements']);
    $same(2, $plan['skipped']);
    $same('There is no statement to run.', (new Sql_query_rules(false))->refusal('-- only a comment'));
    $same(null, $rules->refusal('-- only a comment'));
});

$test('the real submit refuses what the check finds', function () use ($same) {
    $same('Statement 2: A ( is never closed.', App_database::refusal("SELECT 1;\nCREATE TABLE t (id INT", true));
    $same(null, App_database::refusal("SELECT 1;\nCREATE TABLE t (id INT)", true));
    $same('Statement 1: A ( is never closed.', Setup_sql_plan::plan('CREATE TABLE t (id INT; SELECT 1')['refusal']);
});

$test('Database tab: each refused statement, pointing at its keyword', function () use ($same) {
    $sql = "SELECT 1;\n\n-- tidy up\nUPDATE t SET a = 1;\nSET max_statement_time = 0;\nSELECT (2";
    $check = Sql_checker::check($sql, new Sql_query_rules(false));

    $same(4, $check['statements']);
    $same(App_database::refusal($sql, false), $check['refusal']);
    $same([2, 3, 4], array_column($check['problems'], 'number'));
    $same([4, 5, 6], array_column($check['problems'], 'line'));
    $same([1, 1, 8], array_column($check['problems'], 'column'));
    $same(['This changes your database', 'SET does not run here', 'A ( is never closed'], array_column($check['problems'], 'title'));
    $same('UPDATE t SET a = 1;', $check['problems'][0]['source']);
    $same('tick "Allow changes" to run it', $check['problems'][0]['help']);
    $same('opened here, never closed', $check['problems'][2]['label']);
    // Only the SET is fixed by commenting it out
    $same([[5, 5]], $check['fix_lines']);
    $same('4 statements, 3 would be refused.', Sql_checker::summary($check));
});

$test('Database tab: allowed SQL has nothing to show but its count', function () use ($same) {
    $check = Sql_checker::check("SELECT 1; UPDATE t SET a = 1", new Sql_query_rules(true));

    $same(null, $check['refusal']);
    $same([], $check['problems']);
    $same('2 statements, nothing refused. MariaDB still checks names and spelling when it runs.', Sql_checker::summary($check));
});

$test('Database tab: too long is refused as a whole', function () use ($same) {
    $check = Sql_checker::check(str_repeat('SELECT 1;', 10000), new Sql_query_rules(false));

    $same([], $check['problems']);
    $same('That SQL is longer than 64 KB.', Sql_checker::summary($check));
});

$test('Database tab: an executable comment is pointed at', function () use ($same) {
    $check = Sql_checker::check("SELECT 1;\nSELECT /*! SLEEP(1) */ 2", new Sql_query_rules(false));

    $same([2], array_column($check['problems'], 'number'));
    $same('Executable comments (/*! ... */) are not allowed here', $check['problems'][0]['title']);
    $same(8, $check['problems'][0]['column']);
    $same(3, $check['problems'][0]['length']);
});

$test('setup: a dump\'s CREATE DATABASE and USE are refused, commented out by the fix', function () use ($same) {
    $sql = "-- header\nCREATE DATABASE IF NOT EXISTS `app`\n    DEFAULT CHARACTER SET utf8mb4;\nUSE `app`;\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nCREATE TABLE t (id INT);\nSET FOREIGN_KEY_CHECKS = 1;";
    $check = Sql_checker::check($sql, new Setup_sql_rules());

    $same(Setup_sql_plan::plan($sql)['refusal'], $check['refusal']);
    $same(['CREATE DATABASE is not needed here', 'USE is not needed here'], array_column($check['problems'], 'title'));
    $same([2, 4], array_column($check['problems'], 'line'));
    $same(15, $check['problems'][0]['length']);
    $same([[2, 3], [4, 4]], $check['fix_lines']);

    $fixed = explode("\n", $sql);
    foreach ([2, 3, 4] as $line) {
        $fixed[$line - 1] = '-- ' . $fixed[$line - 1];
    }
    $same(null, Setup_sql_plan::plan(implode("\n", $fixed))['refusal']);
});

$test('the real submit refuses database statements too', function () use ($same) {
    $same('Statement 1: Your app is already connected to its own database, so USE and CREATE, ALTER or DROP DATABASE are not needed.', App_database::refusal('DROP DATABASE x; SELECT 1', true));
    $same(null, App_database::database_refusal('CREATE TABLE used (id INT)'));
});

$test('setup: dump transactions are skipped, SET checked as the wizard does', function () use ($same) {
    $sql = "START TRANSACTION;\nSET NAMES utf8mb4;\nSET max_statement_time = 0;\nCREATE TABLE t (id INT);\nCOMMIT;";
    $check = Sql_checker::check($sql, new Setup_sql_rules());

    $same(3, $check['statements']);
    $same(2, $check['skipped']);
    $same(Setup_sql_plan::plan($sql)['refusal'], $check['refusal']);
    $same([3], array_column($check['problems'], 'number'));
    $same('SET does not run here', $check['problems'][0]['title']);
    $same(Setup_sql_plan::set_refusal('SET max_statement_time = 0'), $check['problems'][0]['note']);
    $same('3 statements, 2 transaction or lock statements skipped, 1 would be refused.', Sql_checker::summary($check));
});

$test('a statement sharing a line with another is not offered for commenting out', function () use ($same) {
    $check = Sql_checker::check("SET NAMES utf8mb4; USE app;\nCREATE TABLE t (id INT);", new Setup_sql_rules());

    $same(1, count($check['problems']));
    $same([], $check['fix_lines']);
    $same(null, $check['problems'][0]['help']);
});

$test('only the first problems are listed, then how many more, all fixable', function () use ($same) {
    $check = Sql_checker::check(str_repeat("USE x;\n", 25), new Setup_sql_rules());

    $same(Sql_checker::MAX_PROBLEMS, count($check['problems']));
    $same(5, $check['more_problems']);
    $same(25, count($check['fix_lines']));
});

$test('columns count characters, after tabs and multibyte text', function () use ($same) {
    $check = Sql_checker::check("SELECT 'æø';\n\tSELECT ((1", new Sql_query_rules(false));

    $same(2, $check['problems'][0]['line']);
    $same(10, $check['problems'][0]['column']);
    $same("\tSELECT ((1", $check['problems'][0]['source']);
});

exit($failures > 0 ? 1 : 0);
