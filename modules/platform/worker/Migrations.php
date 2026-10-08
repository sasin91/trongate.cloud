<?php

/**
 * Schema migrations: db/migrations/{NNNN}_{name}.sql, applied in name order
 * by the worker on startup (server.php), so a platform rollout brings its
 * own schema changes. db/schema.sql stays the whole schema for a new
 * database and records every migration as applied, so only existing
 * databases run them.
 *
 * A file holds one or more statements, each ending with ; at the end of a
 * line. MariaDB commits DDL immediately, so each file is recorded right
 * after its statements run; a failing file stops the worker (and later
 * files) until it is fixed, and is retried on the next start.
 *
 * Framework-free.
 */
final class Migrations {

    const DIRECTORY = __DIR__ . '/../../../db/migrations';
    const LOCK = 'tcloud_schema_migrations';
    const LOCK_TIMEOUT_SECONDS = 60;

    /**
     * Apply every migration not yet in schema_migrations.
     *
     * @return list<string> the names applied
     * @throws RuntimeException when the lock is busy or a migration fails
     */
    static function apply(PDO $db, ?callable $log = null, string $directory = self::DIRECTORY): array {
        $log ??= fn(string $message) => error_log($message);

        $create = <<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
              name VARCHAR(190) PRIMARY KEY,
              applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
            SQL;
        $db->exec($create);

        $locked = $db->query("SELECT GET_LOCK('" . self::LOCK . "', " . self::LOCK_TIMEOUT_SECONDS . ')')->fetchColumn();

        if ((int) $locked !== 1) {
            throw new RuntimeException('Another process is applying migrations');
        }

        try {
            $applied = array_flip($db->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
            $done = [];

            foreach (self::pending_files($directory, $applied) as $name => $path) {
                $log("Applying migration {$name}");

                try {
                    foreach (self::statements((string) file_get_contents($path)) as $statement) {
                        $db->exec($statement);
                    }
                } catch (PDOException $e) {
                    throw new RuntimeException("Migration {$name} failed: {$e->getMessage()}", 0, $e);
                }

                $db->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
                $done[] = $name;
            }

            return $done;
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }
    }

    /**
     * Migration files not in $applied, by name, in name order
     *
     * @param array<string, mixed> $applied names as keys
     * @return array<string, string> name => path
     */
    static function pending_files(string $directory, array $applied): array {
        $files = [];

        foreach (glob(rtrim($directory, '/') . '/*.sql') ?: [] as $path) {
            $name = basename($path, '.sql');

            if (!isset($applied[$name])) {
                $files[$name] = $path;
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Split a file into statements: each ends with ; at the end of a line.
     * Lines that are only -- comments are dropped.
     *
     * @return list<string>
     */
    static function statements(string $sql): array {
        $lines = array_filter(
            preg_split('/\r\n|\r|\n/', $sql) ?: [],
            fn(string $line) => !str_starts_with(ltrim($line), '--')
        );
        $statements = [];

        foreach (preg_split('/;[ \t]*$/m', implode("\n", $lines)) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
        }

        return $statements;
    }
}
