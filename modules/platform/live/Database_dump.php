<?php

/**
 * Dumps one MariaDB database to a gzipped .sql file: each table's CREATE
 * TABLE, its rows as INSERTs (generated columns left out: MariaDB computes
 * them) and its triggers, then the views; triggers and views without their
 * DEFINER, so the file imports into any database with the mariadb client.
 *
 * The rows are read inside one consistent snapshot, so writes made while
 * the dump runs do not tear it (InnoDB tables; the CREATE statements are
 * read as they are at that moment).
 *
 * Used by the nightly backup Job's database step (modules/platform/backup,
 * run from the worker image) and by the worker for the platform's own
 * database (Daily_backup_run). backup.php in the app base image keeps its own
 * copy, since that image carries none of the platform's code. Framework-free.
 */
final class Database_dump {

    /** Rows per INSERT statement */
    const ROWS_PER_INSERT = 100;

    /**
     * A connection fit for dumping: rows are streamed, not held in memory
     */
    static function connect(string $host, int $port, string $database, string $user, string $password): PDO {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            // Values exactly as MariaDB sends them: a PHP float would round a DOUBLE
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
    }

    /**
     * Write the whole database to $path (gzip)
     *
     * @param callable(string): void $say told each table and view as it is done
     * @throws RuntimeException when $path cannot be written
     */
    static function write(PDO $pdo, string $database, string $path, callable $say): void {
        $out = gzopen($path, 'wb6');

        if ($out === false) {
            throw new RuntimeException("Cannot write {$path}");
        }

        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

        gzwrite($out, "-- Backup of database {$database} taken by trongate.cloud on " . gmdate('Y-m-d H:i:s') . " UTC\n");
        gzwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n");

        $names = self::list_tables($pdo);

        foreach ($names['tables'] as $table) {
            $rows = self::write_table($pdo, $out, $table);
            $say("Dumped table {$table} ({$rows} rows)");
        }

        foreach ($names['views'] as $view) {
            self::write_view($pdo, $out, $view);
            $say("Dumped view {$view}");
        }

        gzwrite($out, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($out);
        $pdo->exec('COMMIT');
    }

    /**
     * The database's tables and views, by name
     *
     * @return array{tables: list<string>, views: list<string>}
     */
    static function list_tables(PDO $pdo): array {
        $tables = [];
        $views = [];

        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            if ($row[1] === 'VIEW') {
                $views[] = $row[0];
            } else {
                $tables[] = $row[0];
            }
        }

        return ['tables' => $tables, 'views' => $views];
    }

    static function quote_name(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** One table's CREATE TABLE, INSERTs and triggers; returns its row count */
    static function write_table(PDO $pdo, $out, string $table): int {
        $name = self::quote_name($table);
        $create = $pdo->query("SHOW CREATE TABLE {$name}")->fetch(PDO::FETCH_NUM)[1];
        $columns = implode(', ', array_map(self::quote_name(...), self::stored_columns($pdo, $table)));
        $triggers = self::triggers($pdo, $table);

        gzwrite($out, "\n-- Table {$table}\nDROP TABLE IF EXISTS {$name};\n{$create};\n");

        $rows = $pdo->query("SELECT {$columns} FROM {$name}", PDO::FETCH_NUM);
        $batch = [];
        $count = 0;

        foreach ($rows as $row) {
            $values = [];

            foreach ($row as $value) {
                $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
            }

            $batch[] = '(' . implode(', ', $values) . ')';
            $count++;

            if (count($batch) === self::ROWS_PER_INSERT) {
                gzwrite($out, "INSERT INTO {$name} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }

        if ($batch !== []) {
            gzwrite($out, "INSERT INTO {$name} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
        }

        $rows->closeCursor();

        // After the rows, so loading them does not fire the triggers
        foreach ($triggers as $trigger) {
            gzwrite($out, "DELIMITER ;;\n{$trigger};;\nDELIMITER ;\n");
        }

        return $count;
    }

    /**
     * The table's columns that hold their own values, in order: generated
     * columns are left out, since MariaDB refuses a value for them
     *
     * @return list<string>
     */
    static function stored_columns(PDO $pdo, string $table): array {
        $sql = <<<SQL
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_GENERATED = 'NEVER'
            ORDER BY ORDINAL_POSITION
        SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$table]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * The table's CREATE TRIGGER statements, in firing order, without their DEFINER
     *
     * @return list<string>
     */
    static function triggers(PDO $pdo, string $table): array {
        $sql = <<<SQL
            SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?
            ORDER BY ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER
        SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$table]);
        $triggers = [];

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $trigger) {
            $create = (string) $pdo->query('SHOW CREATE TRIGGER ' . self::quote_name((string) $trigger))->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
            $triggers[] = (string) preg_replace('/^CREATE\s+DEFINER\s*=\s*(`[^`]*`|\S+?)@(`[^`]*`|\S+)\s+/i', 'CREATE ', $create);
        }

        return $triggers;
    }

    static function write_view(PDO $pdo, $out, string $view): void {
        $name = self::quote_name($view);
        $create = $pdo->query("SHOW CREATE VIEW {$name}")->fetch(PDO::FETCH_NUM)[1];
        // Without its DEFINER the view belongs to whoever restores it, so it imports anywhere
        $create = preg_replace('/ DEFINER=`[^`]*`@`[^`]*`/', '', $create);

        gzwrite($out, "\n-- View {$view}\nDROP VIEW IF EXISTS {$name};\n{$create};\n");
    }
}
