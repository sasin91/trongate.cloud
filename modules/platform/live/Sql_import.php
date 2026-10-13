<?php

require_once __DIR__ . '/Setup_sql.php';
require_once __DIR__ . '/Sql_stream.php';

/**
 * A .sql file imported from the Database tab, of any size: a database dump
 * (mysqldump, mariadb-dump, phpMyAdmin, TablePlus) or any other SQL, plain
 * or gzipped.
 *
 * The browser uploads it in parts of CHUNK_BYTES (Database::import_chunk),
 * each stored encrypted in app_sql_import_chunk, as the file may hold
 * passwords and personal data. The worker then reads it back part by part
 * (tasks/import_sql.php): first it checks every statement without running
 * any, then, once the person said go, runs them one by one as the app's own
 * user. Each worker task does SLICE_SECONDS of it and queues the next, so
 * deploys and builds are not held up behind a long import, and it carries
 * on after the worker restarts.
 *
 * Statements are checked like the new app's setup SQL (Setup_sql): changes
 * allowed, the transaction and lock statements a dump wraps itself in
 * skipped. On top of that, for dumps:
 *  - the session settings a dump hides in executable comments
 *    (/*!40101 SET NAMES utf8mb4 *\/) are applied, without the ones that
 *    save or restore the client's own (@OLD_...);
 *  - comments before a statement are not sent with it, so a leading
 *    executable comment never runs;
 *  - USE and CREATE DATABASE are skipped: everything goes into the app's database.
 *
 * Framework-free: the panel stores the upload, the worker runs it.
 */
final class Sql_import {

    /**
     * Bytes of the file per upload request. The panel's PHP accepts 8 MB per
     * file (docker/php.ini); smaller parts also finish well within Traefik's
     * 60 s read timeout on a slow connection (4 MB at 1 Mbit/s: about 35 s).
     */
    const CHUNK_BYTES = 4 * 1024 * 1024;

    /**
     * Largest file. It waits in the platform database (a 10 GiB volume, see
     * deploy/k8s/platform/mariadb.yaml) until it ran, so files are kept well
     * below that; gzip one that is larger.
     */
    const MAX_BYTES = 2 * 1024 * 1024 * 1024;

    /** How long one worker task imports before it queues the next */
    const SLICE_SECONDS = 30;

    /** How long one statement may run: an ALTER TABLE or an index on a large table takes a while */
    const STATEMENT_SECONDS = 600;

    /** Uploads never finished, and imports that failed or were stopped, are deleted after this long */
    const KEEP_HOURS = 24;

    /** An import whose worker task died is queued again after this long without progress */
    const STUCK_SECONDS = self::STATEMENT_SECONDS + 120;

    /** Statements that pick or create a database: the import goes into the app's own */
    const DATABASE_STATEMENTS = '/^(USE\b|CREATE\s+(DATABASE|SCHEMA)\b)/i';

    /**
     * Settings a dump sets for its session, as SET name = value, besides
     * NAMES. Not character_set_client and the like: dumps set those around
     * each table and view and restore them from a user variable, which is
     * left out (a view's latin1 would stick), and NAMES already covers them.
     */
    const SETTINGS = ['SQL_MODE', 'TIME_ZONE', 'FOREIGN_KEY_CHECKS', 'UNIQUE_CHECKS', 'SQL_NOTES'];

    /** A value a setting may take: a quoted string, a number or a word, never @variable or an expression */
    const VALUE = '(\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|[A-Za-z0-9_+.:-]+)';

    /** gzip's first two bytes */
    const GZIP_MAGIC = "\x1f\x8b";

    /**
     * What to do with one statement of the file
     *
     * - run: send 'sql' (the statement without its leading comments)
     * - settings: apply 'settings' (name => SET clause), for this and later parts of the import
     * - skip: leave it out
     * - refuse: the import cannot run, 'message' says why
     *
     * @return array{action: 'run'|'settings'|'skip'|'refuse', sql: string, settings: array<string, string>, message: string|null}
     */
    static function step(string $statement): array {
        $step = ['action' => 'skip', 'sql' => '', 'settings' => [], 'message' => null];
        $start = App_database::without_comments($statement);

        if ($start === '') {
            return self::comment_step($statement, $step);
        }

        if (preg_match(Setup_sql::SKIPPED, $start) || preg_match(self::DATABASE_STATEMENTS, $start)) {
            return $step;
        }

        if (preg_match('/^SET\b/i', $start)) {
            return self::settings_step(substr($start, 3), $step);
        }

        if (preg_match('/^DELIMITER\b/i', $start)) {
            $step['action'] = 'refuse';
            $step['message'] = 'DELIMITER is a command of the mysql client, not SQL. Create triggers, procedures and functions in the SQL box, one at a time, and leave them out of the file.';

            return $step;
        }

        $refusal = Setup_sql::statement_refusal($start, $start);

        if ($refusal !== null) {
            $step['action'] = 'refuse';
            $step['message'] = $refusal;

            return $step;
        }

        $step['action'] = 'run';
        $step['sql'] = $start;

        return $step;
    }

    /**
     * A statement that is only comments: the settings in an executable
     * comment's SET (/*!40101 SET NAMES utf8mb4 *\/), anything else is skipped
     *
     * @param array{action: string, sql: string, settings: array<string, string>, message: string|null} $step
     * @return array{action: 'run'|'settings'|'skip'|'refuse', sql: string, settings: array<string, string>, message: string|null}
     */
    static function comment_step(string $statement, array $step): array {
        preg_match_all('#/\*M?!\d*\s*SET\s+(.*?)\*/#is', $statement, $matches);

        foreach ($matches[1] as $clauses) {
            $step = self::settings_step($clauses, $step);
        }

        return $step;
    }

    /**
     * The settings SET $clauses makes that may be applied, as a settings step;
     * a skip when there are none
     *
     * @param array{action: string, sql: string, settings: array<string, string>, message: string|null} $step
     * @return array{action: 'run'|'settings'|'skip'|'refuse', sql: string, settings: array<string, string>, message: string|null}
     */
    static function settings_step(string $clauses, array $step): array {
        $settings = self::settings($clauses);

        if ($settings === []) {
            return $step;
        }

        $step['action'] = 'settings';
        $step['settings'] = self::merge_settings($step['settings'], $settings);

        return $step;
    }

    /**
     * The session settings in the clauses of a SET, by name: NAMES,
     * CHARACTER SET or CHARSET and the SETTINGS given a plain value. Clauses that set
     * user variables (@OLD_SQL_MODE = @@SQL_MODE), restore from them, or set
     * anything else are left out.
     *
     * @return array<string, string> name => clause
     */
    static function settings(string $clauses): array {
        $settings = [];

        foreach (self::clauses($clauses) as $clause) {
            if (preg_match('/^(NAMES|CHARACTER\s+SET|CHARSET)\s+' . self::VALUE . '(\s+COLLATE\s+' . self::VALUE . ')?$/i', $clause)) {
                $settings['NAMES'] = $clause;
                continue;
            }

            if (!preg_match('/^(?:@@SESSION\.|@@|SESSION\s+)?([A-Za-z_]+)\s*:?=\s*' . self::VALUE . '$/i', $clause, $match)) {
                continue;
            }

            $name = strtoupper($match[1]);

            if (in_array($name, self::SETTINGS, true)) {
                $settings[$name] = $name . ' = ' . $match[2];
            }
        }

        return $settings;
    }

    /**
     * The clauses of a SET, split on commas outside quotes and parentheses
     *
     * @return list<string>
     */
    static function clauses(string $clauses): array {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($clauses);

        for ($i = 0; $i < $length; $i++) {
            $char = $clauses[$i];
            $current .= $char;

            if ($quote !== null) {
                if ($char === '\\') {
                    $current .= $clauses[$i + 1] ?? '';
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim(substr($current, 0, -1));
                $current = '';
            }
        }

        $parts[] = trim($current);

        return array_values(array_filter($parts, fn(string $part) => $part !== ''));
    }

    /**
     * $settings with $newer applied after them: each setting once, in the
     * order they were last set, so applying them again ends the same way
     *
     * @param array<string, string> $settings
     * @param array<string, string> $newer
     * @return array<string, string>
     */
    static function merge_settings(array $settings, array $newer): array {
        foreach ($newer as $name => $clause) {
            unset($settings[$name]);
            $settings[$name] = $clause;
        }

        return $settings;
    }

    /**
     * Settings as one SET statement, to apply them again on a new connection
     *
     * @param array<string, string> $settings
     */
    static function settings_sql(array $settings): string {
        return 'SET ' . implode(', ', $settings);
    }

    /**
     * What a statement the check let through does, for the summary shown
     * before it runs: its first keyword (CREATE, INSERT, ...) and, for DROP
     * TABLE, TRUNCATE and DELETE, the table it empties or drops
     *
     * @return array{keyword: string, emptied: string|null}
     */
    static function describe(string $sql): array {
        $keyword = strtoupper((string) preg_replace('/^([A-Za-z]+).*$/s', '$1', $sql));
        $emptied = null;
        $name = '((?:`(?:[^`]|``)+`|[A-Za-z0-9_$]+)(?:\.(?:`(?:[^`]|``)+`|[A-Za-z0-9_$]+))?)';

        if (preg_match('/^(?:DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?|TRUNCATE\s+(?:TABLE\s+)?|DELETE\s+FROM\s+)' . $name . '/i', $sql, $match)) {
            $emptied = str_replace('``', '`', trim(preg_replace('/^.*\./', '', $match[1]) ?? '', '`'));
        }

        return ['keyword' => $keyword, 'emptied' => $emptied];
    }

    /**
     * One part of the file as stored: encrypted with APP_KEY (nonce + ciphertext, binary)
     */
    static function seal(string $bytes): string {
        return (string) base64_decode(Encryptor::encrypt($bytes, Setup_sql::key()));
    }

    /**
     * A stored part as uploaded, or null when it cannot be decrypted (APP_KEY changed)
     */
    static function unseal(string $stored): ?string {
        $bytes = Encryptor::decrypt(base64_encode($stored), Setup_sql::key());

        return $bytes === false ? null : $bytes;
    }

    static function is_gzip(string $first_bytes): bool {
        return str_starts_with($first_bytes, self::GZIP_MAGIC);
    }

    /**
     * How many parts a file of $bytes is uploaded in
     */
    static function chunk_count(int $bytes): int {
        return max(1, intdiv($bytes + self::CHUNK_BYTES - 1, self::CHUNK_BYTES));
    }

    /**
     * Why a file may not be imported, or null when it may
     */
    static function file_refusal(string $file_name, int $bytes): ?string {
        if (!preg_match('/\.(sql|sql\.gz|gz)$/i', $file_name)) {
            return 'Pick a .sql file, or a gzipped one (.sql.gz).';
        }

        if ($bytes <= 0) {
            return 'That file is empty.';
        }

        if ($bytes > self::MAX_BYTES) {
            return 'That file is larger than ' . intdiv(self::MAX_BYTES, 1024 * 1024 * 1024) . ' GB. Gzip it (gzip dump.sql) and import dump.sql.gz.';
        }

        return null;
    }
}
