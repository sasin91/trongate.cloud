<?php

/**
 * Tests for the Database tab's .sql file import: Sql_stream splits SQL that
 * arrives in pieces like App_database::statements() splits it whole, and
 * Sql_import decides per statement of a dump what runs.
 *
 * Run: php tests/Sql_import_test.php (exits non-zero on a failure)
 */

require_once dirname(__DIR__) . '/modules/platform/live/Sql_import.php';

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
 * Every statement of $sql fed in pieces of $size bytes (random sizes when 0)
 *
 * @return list<array{statement: string, end: int, line: int, end_line: int}>
 */
$split = function (string $sql, int $size, int $position = 0, int $line = 1): array {
    $stream = new Sql_stream($position, $line);
    $statements = [];

    for ($i = 0; $i < strlen($sql);) {
        $length = $size > 0 ? $size : mt_rand(1, 6);
        array_push($statements, ...$stream->feed(substr($sql, $i, $length)));
        $i += $length;
    }

    array_push($statements, ...$stream->finish());

    return $statements;
};

$test('splits like App_database::statements, however the SQL is cut into pieces', function () use ($split, $same) {
    $atoms = ['SELECT 1', ';', "'a;b'", '"x\\";y"', '`n;``m`', "-- c;\n", '--x;', "#h;\n", '/* c; */', '/*/;', "\n", ' ', "'it''s'", '\\', '-', '/', '*', "'", 'INSERT', "\r\n"];
    mt_srand(7);

    for ($n = 0; $n < 3000; $n++) {
        $sql = '';

        for ($j = mt_rand(1, 30); $j > 0; $j--) {
            $sql .= $atoms[mt_rand(0, count($atoms) - 1)];
        }

        $streamed = array_column($split($sql, 0), 'statement');
        $not_comments = array_values(array_filter($streamed, fn(string $statement) => App_database::without_comments($statement) !== ''));
        $same(App_database::statements($sql), $not_comments);
    }
});

$test('carries on from any statement with the position and line it ended at', function () use ($split, $same) {
    $sql = "CREATE TABLE a (id INT);\n-- note; here\nINSERT INTO a VALUES (1), (2);\n\n/* x;\ny */ INSERT INTO a VALUES ('a;\nb');\nSELECT 1";
    $all = $split($sql, 5);

    $same(['CREATE TABLE a (id INT)', "-- note; here\nINSERT INTO a VALUES (1), (2)", "/* x;\ny */ INSERT INTO a VALUES ('a;\nb')", 'SELECT 1'], array_column($all, 'statement'));
    $same([1, 2, 5, 8], array_column($all, 'line'));

    foreach ($all as $i => $statement) {
        $same(array_slice($all, $i + 1), $split(substr($sql, $statement['end']), 3, $statement['end'], $statement['end_line']));
    }
});

$test('a dump\'s session settings are applied without the ones that save or restore the client\'s', function () use ($same) {
    $same(['action' => 'settings', 'sql' => '', 'settings' => ['NAMES' => 'NAMES utf8mb4'], 'message' => null], Sql_import::step('/*!40101 SET NAMES utf8mb4 */'));
    $same(['UNIQUE_CHECKS' => 'UNIQUE_CHECKS = 0'], Sql_import::step('/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */')['settings']);
    $same(['SQL_MODE' => "SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO,STRICT_TRANS_TABLES'"], Sql_import::step("/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO,STRICT_TRANS_TABLES' */")['settings']);
    $same('skip', Sql_import::step('/*!40101 SET SQL_MODE=@OLD_SQL_MODE */')['action']);
    $same('skip', Sql_import::step('/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */')['action']);
    $same(['TIME_ZONE' => 'TIME_ZONE = "+00:00"'], Sql_import::step('SET time_zone = "+00:00"')['settings']);
    $same('skip', Sql_import::step('/*!50001 SET character_set_client = latin1 */')['action']);
    $same(['NAMES' => 'NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'], Sql_import::step('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci')['settings']);
});

$test('settings are applied again in the order they were last set', function () use ($same) {
    $same(['B' => 'b', 'A' => 'a2'], Sql_import::merge_settings(['A' => 'a', 'B' => 'b'], ['A' => 'a2']));
});

$test('settings that could lift a limit or leave the session are left out', function () use ($same) {
    $same('skip', Sql_import::step('SET max_statement_time = 0')['action']);
    $same('skip', Sql_import::step('SET GLOBAL foreign_key_checks = 0')['action']);
    $same('skip', Sql_import::step('SET SESSION TRANSACTION READ WRITE')['action']);
    $same('skip', Sql_import::step('SET foreign_key_checks = (SELECT 0)')['action']);
    $same(['FOREIGN_KEY_CHECKS' => 'FOREIGN_KEY_CHECKS = 0'], Sql_import::step('SET foreign_key_checks = 0, max_statement_time = 0')['settings']);
});

$test('a dump\'s wrapping and database statements are skipped, the rest run without leading comments', function () use ($same) {
    foreach (['LOCK TABLES `users` WRITE', 'UNLOCK TABLES', 'START TRANSACTION', 'COMMIT', 'USE `shop`', 'CREATE DATABASE IF NOT EXISTS shop', '/*!40000 ALTER TABLE `users` DISABLE KEYS */', "/*M!999999\\- enable the sandbox mode */ \n-- MariaDB dump 10.19"] as $statement) {
        $same('skip', Sql_import::step($statement)['action']);
    }

    $step = Sql_import::step("/*M!999999\\- enable the sandbox mode */\n-- Table structure\nDROP TABLE IF EXISTS `users`");
    $same(['run', 'DROP TABLE IF EXISTS `users`'], [$step['action'], $step['sql']]);
});

$test('what may not run anywhere in the panel is refused', function () use ($same) {
    $same('refuse', Sql_import::step('CREATE TABLE t (id INT) /*!50100 PARTITION BY HASH (id) */')['action']);
    $same('refuse', Sql_import::step("SELECT * INTO OUTFILE '/tmp/x' FROM users")['action']);
    $same('refuse', Sql_import::step('GRANT ALL ON *.* TO someone')['action']);
    $same('refuse', Sql_import::step('LOAD DATA INFILE \'/etc/passwd\' INTO TABLE t')['action']);
    $same('refuse', Sql_import::step('DELIMITER ;;')['action']);
});

$test('the check names tables a statement drops or empties', function () use ($same) {
    $same(['keyword' => 'DROP', 'emptied' => 'users'], Sql_import::describe('DROP TABLE IF EXISTS `users`'));
    $same(['keyword' => 'TRUNCATE', 'emptied' => 'orders'], Sql_import::describe('TRUNCATE TABLE shop.orders'));
    $same(['keyword' => 'DELETE', 'emptied' => 'my table'], Sql_import::describe('DELETE FROM `my table` WHERE id > 3'));
    $same(['keyword' => 'INSERT', 'emptied' => null], Sql_import::describe('INSERT INTO users VALUES (1)'));
});

$test('files are .sql or gzipped, and not empty', function () use ($same) {
    $same(null, Sql_import::file_refusal('dump.sql', 10));
    $same(null, Sql_import::file_refusal('dump.SQL.gz', 10));
    $same('Pick a .sql file, or a gzipped one (.sql.gz).', Sql_import::file_refusal('dump.zip', 10));
    $same('That file is empty.', Sql_import::file_refusal('dump.sql', 0));
    $same(3, Sql_import::chunk_count(Sql_import::CHUNK_BYTES * 2 + 1));
    $same(1, Sql_import::chunk_count(1));
    $same(true, Sql_import::is_gzip((string) gzencode('SELECT 1')));
});

exit($failures === 0 ? 0 : 1);
