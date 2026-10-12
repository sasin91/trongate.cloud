<?php

/**
 * Tests for Setup_sql: the .sql files the new-app wizard offers, and how
 * the SQL a new app's database starts with is checked and split.
 *
 * Run: php tests/Setup_sql_test.php (exits non-zero on a failure)
 */

require_once dirname(__DIR__) . '/modules/platform/live/Setup_sql.php';

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

$test('schema.sql and Trongate setup.sql come first and ticked, the rest by name', function () use ($same) {
    $paths = [
        'README.md',
        'modules/shop/02_products.sql',
        'modules/shop/10_reviews.sql',
        'modules/shop/01_tables.sql',
        'modules/setup/sql/setup.sql',
        'modules/welcome/schema.sql',
        'db/schema.sql',
        'db/migrations/0001_users.sql',
        'dump.sql',
        'vendor/acme/lib/test.sql',
        'public/js/app.js',
        'modules/a/b/c/deep.sql',
    ];

    $same([
        ['path' => 'db/schema.sql', 'checked' => true],
        ['path' => 'modules/setup/sql/setup.sql', 'checked' => true],
        ['path' => 'modules/welcome/schema.sql', 'checked' => true],
        ['path' => 'db/migrations/0001_users.sql', 'checked' => false],
        ['path' => 'dump.sql', 'checked' => false],
        ['path' => 'modules/shop/01_tables.sql', 'checked' => false],
        ['path' => 'modules/shop/02_products.sql', 'checked' => false],
        ['path' => 'modules/shop/10_reviews.sql', 'checked' => false],
    ], Setup_sql::candidates($paths));
});

$test('a repository without .sql files offers nothing', function () use ($same) {
    $same([], Setup_sql::candidates(['index.php', 'config/config.php']));
});

$test('a dump runs without its transaction and lock statements', function () use ($same) {
    $sql = <<<'SQL'
    -- phpMyAdmin SQL Dump
    SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
    START TRANSACTION;
    SET time_zone = "+00:00";
    /*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
    /*!40101 SET NAMES utf8mb4 */;
    SET FOREIGN_KEY_CHECKS=0;
    CREATE TABLE `posts` (`id` int(11) NOT NULL, `title` varchar(255) DEFAULT 'a;b');
    LOCK TABLES `posts` WRITE;
    INSERT INTO `posts` VALUES (1,'Hello; world');
    UNLOCK TABLES;
    COMMIT;
    SQL;

    $plan = Setup_sql::plan($sql);

    $same(null, $plan['refusal']);
    $same(4, $plan['skipped']);
    $same([
        "-- phpMyAdmin SQL Dump\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\"",
        'SET time_zone = "+00:00"',
        'SET FOREIGN_KEY_CHECKS=0',
        "CREATE TABLE `posts` (`id` int(11) NOT NULL, `title` varchar(255) DEFAULT 'a;b')",
        "INSERT INTO `posts` VALUES (1,'Hello; world')",
    ], $plan['statements']);
});

$test('SET of anything but a dump\'s session settings is refused', function () use ($same) {
    $same('Statement 1: Only SET NAMES, CHARACTER SET, SQL_MODE, TIME_ZONE, FOREIGN_KEY_CHECKS, UNIQUE_CHECKS or SQL_NOTES runs here, one per statement.', Setup_sql::plan('SET max_statement_time = 0')['refusal']);
    $same('Statement 2: Only SET NAMES, CHARACTER SET, SQL_MODE, TIME_ZONE, FOREIGN_KEY_CHECKS, UNIQUE_CHECKS or SQL_NOTES runs here, one per statement.', Setup_sql::plan("CREATE TABLE a (id INT);\nSET NAMES utf8mb4, max_statement_time = 0")['refusal']);
    $same(null, Setup_sql::plan("SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_DATE'")['refusal']);
    $same(null, Setup_sql::plan('SET SESSION foreign_key_checks = 1')['refusal']);
});

$test('statements the SQL box refuses are refused, changes allowed', function () use ($same) {
    $same('Statement 2: Only CREATE, ALTER, DROP, TRUNCATE, RENAME, INSERT, UPDATE, DELETE, REPLACE, SELECT and SET NAMES, SQL_MODE, TIME_ZONE or FOREIGN_KEY_CHECKS run here.', Setup_sql::plan("CREATE TABLE a (id INT);\nGRANT ALL ON *.* TO 'x'")['refusal']);
    $same('Statement 1: Reading or writing files on the database server is not allowed.', Setup_sql::plan("SELECT * FROM a INTO OUTFILE '/tmp/a'")['refusal']);
    $same('Statement 1: Executable comments (/*! ... */) are not allowed here.', Setup_sql::plan('CREATE TABLE a (id INT) /*!50100 PARTITION BY HASH(id) */')['refusal']);
    $same(['DROP TABLE IF EXISTS a', 'CREATE TABLE a (id INT)'], Setup_sql::plan("DROP TABLE IF EXISTS a;\nCREATE TABLE a (id INT);")['statements']);
});

$test('a statement over the SQL box\'s 64 KB is fine', function () use ($same) {
    $values = implode(',', array_fill(0, 10000, "(1,'abcdefgh')"));
    $plan = Setup_sql::plan("INSERT INTO a VALUES {$values};");

    $same(null, $plan['refusal']);
    $same(1, count($plan['statements']));
});

$test('join gives SQL that plans to the same statements, also after a trailing comment', function () use ($same) {
    $statements = ["CREATE TABLE a (id INT)\n-- the ids", 'INSERT INTO a VALUES (1)', "INSERT INTO a VALUES ('#1')"];
    $sql = Setup_sql::join($statements);

    $same("CREATE TABLE a (id INT)\n-- the ids\n;\n\nINSERT INTO a VALUES (1);\n\nINSERT INTO a VALUES ('#1')\n;\n\n", $sql);
    $same($statements, Setup_sql::plan($sql)['statements']);
});

$test('Trongate v2\'s setup.sql plans without a refusal', function () use ($same) {
    $sql = <<<'SQL'
    -- =============================================
    -- Trongate Framework Setup SQL
    -- =============================================

    CREATE TABLE IF NOT EXISTS `trongate_user_levels` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `level_title` varchar(125) DEFAULT '',
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    INSERT IGNORE INTO `trongate_user_levels` (`id`, `level_title`) VALUES
    (1, 'admin');
    SQL;

    $plan = Setup_sql::plan($sql);

    $same(null, $plan['refusal']);
    $same(2, count($plan['statements']));
});

if ($failures > 0) {
    echo "{$failures} failed\n";
    exit(1);
}
