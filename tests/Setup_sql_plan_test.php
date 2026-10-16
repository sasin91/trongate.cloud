<?php

/**
 * Tests for Setup_sql_plan: the .sql files the new-app wizard offers, and how
 * the SQL a new app's database starts with is checked and split.
 *
 * Run: php tests/Setup_sql_plan_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/modules/platform/live/Setup_sql_plan.php';

test('schema.sql and Trongate setup.sql come first and ticked, the rest by name', function () {
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

    assert_same([
        ['path' => 'db/schema.sql', 'checked' => true],
        ['path' => 'modules/setup/sql/setup.sql', 'checked' => true],
        ['path' => 'modules/welcome/schema.sql', 'checked' => true],
        ['path' => 'db/migrations/0001_users.sql', 'checked' => false],
        ['path' => 'dump.sql', 'checked' => false],
        ['path' => 'modules/shop/01_tables.sql', 'checked' => false],
        ['path' => 'modules/shop/02_products.sql', 'checked' => false],
        ['path' => 'modules/shop/10_reviews.sql', 'checked' => false],
    ], Setup_sql_plan::candidates($paths));
});

test('a repository without .sql files offers nothing', function () {
    assert_same([], Setup_sql_plan::candidates(['index.php', 'config/config.php']));
});

test('a dump runs without its transaction and lock statements', function () {
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

    $plan = Setup_sql_plan::plan($sql);

    assert_same(null, $plan['refusal']);
    assert_same(4, $plan['skipped']);
    assert_same([
        "-- phpMyAdmin SQL Dump\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\"",
        'SET time_zone = "+00:00"',
        'SET FOREIGN_KEY_CHECKS=0',
        "CREATE TABLE `posts` (`id` int(11) NOT NULL, `title` varchar(255) DEFAULT 'a;b')",
        "INSERT INTO `posts` VALUES (1,'Hello; world')",
    ], $plan['statements']);
});

test('SET of anything but a dump\'s session settings is refused', function () {
    assert_same('Statement 1: Only SET NAMES, CHARACTER SET, SQL_MODE, TIME_ZONE, FOREIGN_KEY_CHECKS, UNIQUE_CHECKS or SQL_NOTES runs here, one per statement.', Setup_sql_plan::plan('SET max_statement_time = 0')['refusal']);
    assert_same('Statement 2: Only SET NAMES, CHARACTER SET, SQL_MODE, TIME_ZONE, FOREIGN_KEY_CHECKS, UNIQUE_CHECKS or SQL_NOTES runs here, one per statement.', Setup_sql_plan::plan("CREATE TABLE a (id INT);\nSET NAMES utf8mb4, max_statement_time = 0")['refusal']);
    assert_same(null, Setup_sql_plan::plan("SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_DATE'")['refusal']);
    assert_same(null, Setup_sql_plan::plan('SET SESSION foreign_key_checks = 1')['refusal']);
    assert_same(null, Setup_sql_plan::plan('SET CHARSET utf8mb4')['refusal']);
    assert_same(null, Setup_sql_plan::plan('SET CHARACTER SET utf8mb4')['refusal']);
});

test('statements the SQL box refuses are refused, changes allowed', function () {
    assert_same('Statement 2: Only CREATE, ALTER, DROP, TRUNCATE, RENAME, INSERT, UPDATE, DELETE, REPLACE, SELECT and SET NAMES, SQL_MODE, TIME_ZONE or FOREIGN_KEY_CHECKS run here.', Setup_sql_plan::plan("CREATE TABLE a (id INT);\nGRANT ALL ON *.* TO 'x'")['refusal']);
    assert_same('Statement 1: Reading or writing files on the database server is not allowed.', Setup_sql_plan::plan("SELECT * FROM a INTO OUTFILE '/tmp/a'")['refusal']);
    assert_same('Statement 1: Executable comments (/*! ... */) are not allowed here.', Setup_sql_plan::plan('CREATE TABLE a (id INT) /*!50100 PARTITION BY HASH(id) */')['refusal']);
    assert_same(['DROP TABLE IF EXISTS a', 'CREATE TABLE a (id INT)'], Setup_sql_plan::plan("DROP TABLE IF EXISTS a;\nCREATE TABLE a (id INT);")['statements']);
});

test('a statement over the SQL box\'s 64 KB is fine', function () {
    $values = implode(',', array_fill(0, 10000, "(1,'abcdefgh')"));
    $plan = Setup_sql_plan::plan("INSERT INTO a VALUES {$values};");

    assert_same(null, $plan['refusal']);
    assert_same(1, count($plan['statements']));
});

test('join gives SQL that plans to the same statements, also after a trailing comment', function () {
    $statements = ["CREATE TABLE a (id INT)\n-- the ids", 'INSERT INTO a VALUES (1)', "INSERT INTO a VALUES ('#1')"];
    $sql = Setup_sql_plan::join($statements);

    assert_same("CREATE TABLE a (id INT)\n-- the ids\n;\n\nINSERT INTO a VALUES (1);\n\nINSERT INTO a VALUES ('#1')\n;\n\n", $sql);
    assert_same($statements, Setup_sql_plan::plan($sql)['statements']);
});

test('Trongate v2\'s setup.sql plans without a refusal', function () {
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

    $plan = Setup_sql_plan::plan($sql);

    assert_same(null, $plan['refusal']);
    assert_same(2, count($plan['statements']));
});

test('the stored SQL is encrypted with APP_KEY and comes back the same', function () {
    putenv('APP_KEY=' . Encryptor::generate_key());
    $sql = "INSERT INTO users (email, password) VALUES ('a@example.com', 'secret');\n";
    $stored = Setup_sql_plan::encrypt($sql);

    assert_same(false, str_contains($stored, 'secret'));
    assert_same($sql, Setup_sql_plan::decrypt($stored));

    putenv('APP_KEY=' . Encryptor::generate_key());
    assert_same(null, Setup_sql_plan::decrypt($stored));
});

finish_tests();
