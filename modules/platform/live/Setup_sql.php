<?php

require_once __DIR__ . '/App_database.php';
require_once __DIR__ . '/../../encryption/Encryptor.php';

/**
 * The SQL a new app's database starts with: .sql files from its repository
 * and SQL typed into the new-app wizard. The wizard stores it in
 * app.setup_sql, and the first deploy runs it once, right after the
 * database is created (Platform_task::run_setup_sql).
 *
 * Statements are checked like the Database tab's SQL box, with changes
 * allowed and without its size limit. The transaction and lock statements a
 * database dump wraps itself in are skipped, as each statement runs on its
 * own; SET is allowed for the session settings dumps use.
 *
 * Framework-free: the panel plans it, the worker runs it.
 */
final class Setup_sql {

    /** Where schema.sql is looked for, in this order (also the Database tab's Schema view) */
    const SCHEMA_PATHS = ['schema.sql', 'database/schema.sql', 'db/schema.sql', 'sql/schema.sql'];

    /** Trongate's own tables: v2's setup module, v1's administrators module */
    const TRONGATE_SETUP_PATHS = ['modules/setup/sql/setup.sql', 'modules/trongate_administrators/setup.sql'];

    /**
     * Where else .sql files are offered: the repository root, database/, db/,
     * sql/ and module folders (Trongate Control runs a module's *.sql, also a
     * child module's)
     */
    const FILE_PATTERNS = [
        '#^[^/]+\.sql$#i',
        '#^(database|db|sql)/.+\.sql$#i',
        '#^modules/[^/]+/[^/]+\.sql$#i',
        '#^modules/[^/]+/[^/]+/[^/]+\.sql$#i',
    ];

    /** The platform database's max_allowed_packet (deploy/k8s/platform/mariadb.yaml) */
    const PLATFORM_PACKET_BYTES = 64 * 1024 * 1024;

    /**
     * All of it together. Stored encrypted (base64, so 4 bytes per 3) it
     * must fit in one write to the platform database, with 1 MB to spare
     * for the rest of the UPDATE and the nonce
     */
    const MAX_BYTES = self::PLATFORM_PACKET_BYTES / 4 * 3 - 1024 * 1024;

    /** Statements a dump wraps itself in; each statement runs on its own, so they are left out */
    const SKIPPED = '/^(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|(UN)?LOCK\s+TABLES?)\b/i';

    /** Session settings a dump sets; SET of anything else (max_statement_time) is refused */
    const ALLOWED_SET = '/^SET\s+(SESSION\s+|@@SESSION\.|@@)?(NAMES|CHARACTER\s+SET|SQL_MODE|TIME_ZONE|FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|SQL_NOTES)\b/i';

    /**
     * The .sql files in a repository to offer, from its tree's paths: the
     * ones a new app most likely starts with first and ticked (schema.sql,
     * then Trongate's setup.sql), then the rest by name
     *
     * @param list<string> $paths every file in the repository
     * @return list<array{path: string, checked: bool}>
     */
    static function candidates(array $paths): array {
        $first = [];
        $rest = [];

        foreach (array_merge(self::SCHEMA_PATHS, self::TRONGATE_SETUP_PATHS) as $path) {
            if (in_array($path, $paths, true)) {
                $first[] = ['path' => $path, 'checked' => true];
            }
        }

        $first_paths = array_column($first, 'path');

        foreach ($paths as $path) {
            if (in_array($path, $first_paths, true) || !self::is_offered($path)) {
                continue;
            }

            $rest[] = ['path' => $path, 'checked' => basename($path) === 'schema.sql'];
        }

        usort($rest, [self::class, 'compare_paths']);

        return array_merge($first, $rest);
    }

    static function is_offered(string $path): bool {
        foreach (self::FILE_PATTERNS as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ticked schema.sql files first, then by name, numbers in order (01_tables.sql before 02_data.sql)
     *
     * @param array{path: string, checked: bool} $a
     * @param array{path: string, checked: bool} $b
     */
    static function compare_paths(array $a, array $b): int {
        if ($a['checked'] !== $b['checked']) {
            return $a['checked'] ? -1 : 1;
        }

        return strnatcasecmp($a['path'], $b['path']);
    }

    /**
     * The statements to run, how many were skipped, or why the SQL cannot run
     *
     * @return array{statements: list<string>, skipped: int, refusal: string|null}
     */
    static function plan(string $sql): array {
        $plan = ['statements' => [], 'skipped' => 0, 'refusal' => null];

        if (strlen($sql) > self::MAX_BYTES) {
            $plan['refusal'] = 'That is more than ' . self::max_megabytes() . ' MB of SQL. Import it as a file from the Database tab once the app runs.';

            return $plan;
        }

        foreach (App_database::statements($sql) as $i => $statement) {
            $start = App_database::without_comments($statement);

            if (preg_match(self::SKIPPED, $start)) {
                $plan['skipped']++;
                continue;
            }

            $refusal = self::statement_refusal($statement, $start);

            if ($refusal !== null) {
                $plan['refusal'] = 'Statement ' . ($i + 1) . ': ' . $refusal;

                return $plan;
            }

            $plan['statements'][] = $statement;
        }

        return $plan;
    }

    /**
     * Why one statement may not run, or null when it may
     *
     * @param string $start the statement without its leading comments
     */
    static function statement_refusal(string $statement, string $start): ?string {
        $content_refusal = App_database::content_refusal($statement);

        if ($content_refusal !== null) {
            return $content_refusal;
        }

        if (preg_match('/^SET\b/i', $start)) {
            return self::set_refusal($start);
        }

        if (App_database::keyword_kind($statement) === null) {
            return 'Only CREATE, ALTER, DROP, TRUNCATE, RENAME, INSERT, UPDATE, DELETE, REPLACE, SELECT and SET NAMES, SQL_MODE, TIME_ZONE or FOREIGN_KEY_CHECKS run here.';
        }

        return null;
    }

    /**
     * SET of one session setting a dump uses, or why not. Quoted values may hold
     * commas (SQL_MODE), so a second setting is looked for outside them.
     */
    static function set_refusal(string $statement): ?string {
        $unquoted = (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"/s', "''", $statement);

        if (!preg_match(self::ALLOWED_SET, $unquoted) || str_contains($unquoted, ',')) {
            return 'Only SET NAMES, CHARACTER SET, SQL_MODE, TIME_ZONE, FOREIGN_KEY_CHECKS, UNIQUE_CHECKS or SQL_NOTES runs here, one per statement.';
        }

        return null;
    }

    static function max_megabytes(): int {
        return intdiv(self::MAX_BYTES, 1024 * 1024);
    }

    /**
     * The SQL as stored in app.setup_sql: it may hold passwords and keys, so
     * encrypted with APP_KEY like the app's secrets
     */
    static function encrypt(string $sql): string {
        return Encryptor::encrypt($sql, self::key());
    }

    /**
     * app.setup_sql as SQL again, or null when it cannot be decrypted (APP_KEY changed)
     */
    static function decrypt(string $stored): ?string {
        $sql = Encryptor::decrypt($stored, self::key());

        return $sql === false ? null : $sql;
    }

    static function key(): string {
        return defined('APP_KEY') ? (string) constant('APP_KEY') : (string) getenv('APP_KEY');
    }

    /**
     * Statements as SQL again, each ending in ";". plan() of it gives the
     * same statements.
     *
     * @param list<string> $statements
     */
    static function join(array $statements): string {
        $sql = '';

        foreach ($statements as $statement) {
            $sql .= $statement . self::terminator($statement);
        }

        return $sql;
    }

    /**
     * ";" and a blank line; on a line of its own after a statement whose
     * last line has a comment, which would otherwise swallow it
     */
    static function terminator(string $statement): string {
        $last_line = (string) strrchr("\n" . $statement, "\n");

        if (preg_match('/--|#|\/\*/', $last_line)) {
            return "\n;\n\n";
        }

        return ";\n\n";
    }
}
