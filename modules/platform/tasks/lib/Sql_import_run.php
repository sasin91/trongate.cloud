<?php

require_once __DIR__ . '/Platform_task.php';
require_once __DIR__ . '/../../live/Sql_import.php';

/**
 * One worker task's share of an import (tasks/import_sql.php): up to
 * Sql_import::SLICE_SECONDS of checking or running the file's statements,
 * from where the last share stopped (app_sql_import.position).
 *
 * Checking ('checking') reads every statement without running any, and
 * stops at the first one that may not run. It counts what the file does for
 * the summary the person sees before they run it ('checked').
 *
 * Running ('running') runs them one after another as the app's own user.
 * Each statement's progress is saved right after it ran, so a stop, a
 * failure or a worker restart carries on from the next one. Session
 * settings (SET NAMES, FOREIGN_KEY_CHECKS, ...) are saved too and applied
 * again on each new connection.
 *
 * Framework-free.
 */
final class Sql_import_run {

    /** The share ended with more to do: queue the next task */
    const MORE = 'more';

    /** The share ended because the import is done, failed, or was stopped */
    const ENDED = 'ended';

    private PDO $db;

    private Task_log $log;

    /** @var array<string, mixed> the app_sql_import row */
    private array $import;

    private ?App_database $database = null;

    private float $deadline;

    private int $position;

    private int $line;

    /** Statements in the file so far, run or skipped */
    private int $number;

    private int $run;

    private int $skipped;

    /** @var array<string, string> */
    private array $settings;

    /** @var array<string, int> statements to run by first keyword, while checking */
    private array $keywords;

    /** @var array<string, string> tables a statement drops or empties, while checking */
    private array $emptied;

    /**
     * @param array<string, mixed> $import the app_sql_import row
     */
    function __construct(PDO $db, Task_log $log, array $import) {
        $this->db = $db;
        $this->log = $log;
        $this->import = $import;
        $this->deadline = microtime(true) + Sql_import::SLICE_SECONDS;
        $this->position = (int) $import['position'];
        $this->line = (int) $import['line'];
        $this->number = (int) $import['statement_number'];
        $this->run = (int) $import['statements_run'];
        $this->skipped = (int) $import['statements_skipped'];
        $this->settings = self::decode((string) ($import['settings'] ?? ''));

        $summary = self::decode((string) ($import['summary'] ?? ''));
        $this->keywords = $summary['keywords'] ?? [];
        $this->emptied = $summary['emptied'] ?? [];
    }

    /**
     * Check or run statements until the deadline, the end of the file, a
     * failure, or a stop
     *
     * @param array<string, mixed>|null $server the app's database_server row; null while checking
     * @return string MORE or ENDED
     */
    function share(?array $server): string {
        if ($this->import['status'] === 'running') {
            $opened = $this->open_database($server);

            if (!$opened) {
                return self::ENDED;
            }
        }

        foreach ($this->statements() as $statement) {
            $outcome = $this->step($statement);

            if ($outcome !== null) {
                return $outcome;
            }
        }

        return $this->file_ended();
    }

    /**
     * The file's statements from the saved position on, read one stored part at a time
     *
     * @return Generator<int, array{statement: string, end: int, line: int, end_line: int}>
     */
    private function statements(): Generator {
        $stream = new Sql_stream($this->position, $this->line);

        foreach ($this->pieces() as $piece) {
            yield from $stream->feed($piece);
        }

        yield from $stream->finish();
    }

    /**
     * The SQL from the saved position on, in pieces: stored parts decrypted
     * and, for a gzipped file, inflated
     *
     * @return Generator<int, string>
     */
    private function pieces(): Generator {
        $import_id = (int) $this->import['id'];
        $compressed = (bool) $this->import['compressed'];
        $inflate = $compressed ? inflate_init(ZLIB_ENCODING_GZIP) : null;
        $offset = 0;
        $chunks = Platform_task::rows($this->db, 'SELECT seq, bytes FROM app_sql_import_chunk WHERE import_id = ? ORDER BY seq', [$import_id]);

        foreach ($chunks as $chunk) {
            // Parts already done are not read again, unless they must be inflated to get further
            if (!$compressed && $offset + (int) $chunk['bytes'] <= $this->position) {
                $offset += (int) $chunk['bytes'];
                continue;
            }

            $bytes = $this->chunk($import_id, (int) $chunk['seq']);

            if ($inflate !== null) {
                $bytes = inflate_add($inflate, $bytes, ZLIB_SYNC_FLUSH);

                if ($bytes === false) {
                    throw new RuntimeException('The file is not a valid gzip file.');
                }
            }

            $piece = $this->after_position($bytes, $offset);
            $offset += strlen($bytes);

            if ($piece !== '') {
                yield $piece;
            }
        }
    }

    private function chunk(int $import_id, int $seq): string {
        $row = Platform_task::row($this->db, 'SELECT data FROM app_sql_import_chunk WHERE import_id = ? AND seq = ?', [$import_id, $seq]);
        $bytes = $row === null ? null : Sql_import::unseal((string) $row['data']);

        if ($bytes === null) {
            throw new RuntimeException('Part ' . ($seq + 1) . ' of the file could not be read back (APP_KEY changed?). Upload it again.');
        }

        return $bytes;
    }

    /**
     * What of $bytes, which start at $offset of the SQL, comes after the saved position
     */
    private function after_position(string $bytes, int $offset): string {
        if ($offset >= $this->position) {
            return $bytes;
        }

        return substr($bytes, $this->position - $offset);
    }

    /**
     * Check or run one statement; null to carry on, MORE or ENDED to stop here
     *
     * @param array{statement: string, end: int, line: int, end_line: int} $statement
     */
    private function step(array $statement): ?string {
        $step = Sql_import::step($statement['statement']);
        $number = $this->number + 1;

        if ($step['action'] === 'refuse') {
            return $this->fail("Statement {$number} (line {$statement['line']}) cannot run here: {$step['message']}", false);
        }

        if ($this->import['status'] === 'checking') {
            $this->count($step);
        } else {
            $error = $this->apply($step);

            if ($error !== null) {
                return $this->fail("Statement {$number} (line {$statement['line']}) failed: {$error}", true);
            }
        }

        $this->number = $number;
        $this->position = $statement['end'];
        $this->line = $statement['end_line'];

        if ($this->import['status'] === 'running' && !$this->save_progress()) {
            $this->log->info('Stopped by the person who started it');

            return self::ENDED;
        }

        if (microtime(true) >= $this->deadline) {
            return $this->share_ended();
        }

        return null;
    }

    /**
     * While checking: count what the statement does
     *
     * @param array{action: string, sql: string, settings: array<string, string>, message: string|null} $step
     */
    private function count(array $step): void {
        if ($step['action'] !== 'run') {
            $this->skipped += $step['action'] === 'skip' ? 1 : 0;

            return;
        }

        $this->run++;
        $what = Sql_import::describe($step['sql']);
        $this->keywords[$what['keyword']] = ($this->keywords[$what['keyword']] ?? 0) + 1;

        if ($what['emptied'] !== null) {
            $this->emptied[strtolower($what['emptied'])] = $what['emptied'];
        }
    }

    /**
     * While running: run the statement, or apply its settings
     *
     * @param array{action: string, sql: string, settings: array<string, string>, message: string|null} $step
     * @return string|null MariaDB's message when it failed
     */
    private function apply(array $step): ?string {
        if ($step['action'] === 'skip' || $this->skip_first()) {
            $this->skipped++;

            return null;
        }

        if ($step['action'] === 'settings') {
            $this->settings = Sql_import::merge_settings($this->settings, $step['settings']);

            return $this->database->import_statement(Sql_import::settings_sql($step['settings']));
        }

        $error = $this->database->import_statement($step['sql']);

        if ($error === null) {
            $this->run++;
        }

        return $error;
    }

    /**
     * Whether the person chose to skip the statement that failed: the first one of this share
     */
    private function skip_first(): bool {
        if (empty($this->import['skip_first'])) {
            return false;
        }

        $this->import['skip_first'] = 0;
        Platform_task::execute($this->db, 'UPDATE app_sql_import SET skip_first = 0 WHERE id = ?', [(int) $this->import['id']]);

        return true;
    }

    /**
     * The app's database as its own user, with the import's settings so far
     *
     * @param array<string, mixed>|null $server
     */
    private function open_database(?array $server): bool {
        if ($server === null) {
            $this->fail('This app has no database yet.', true);

            return false;
        }

        try {
            $this->database = App_database::open($server, (int) $this->import['app_id'], Sql_import::STATEMENT_SECONDS);
        } catch (PDOException $e) {
            $this->fail('Could not reach the database: ' . App_database::error_message($e), true);

            return false;
        }

        $this->database->prepare_import();

        if ($this->settings === []) {
            return true;
        }

        $error = $this->database->import_statement(Sql_import::settings_sql($this->settings));

        if ($error !== null) {
            $this->fail('Applying the settings of the statements before failed: ' . $error, true);

            return false;
        }

        return true;
    }

    /**
     * Save where running got to; false when the person stopped the import meanwhile
     */
    private function save_progress(): bool {
        $saved = Platform_task::execute(
            $this->db,
            "UPDATE app_sql_import SET position = ?, line = ?, statement_number = ?, statements_run = ?, statements_skipped = ?, settings = ?
             WHERE id = ? AND status = 'running'",
            [$this->position, $this->line, $this->number, $this->run, $this->skipped, self::encode($this->settings), (int) $this->import['id']],
        );

        // Unchanged values match too, so ask when nothing was written
        return $saved === 1 || $this->status() === 'running';
    }

    private function status(): string {
        $row = Platform_task::row($this->db, 'SELECT status FROM app_sql_import WHERE id = ?', [(int) $this->import['id']]);

        return (string) ($row['status'] ?? '');
    }

    /**
     * Out of time: save where checking got to, and queue the next share
     */
    private function share_ended(): string {
        if ($this->import['status'] === 'checking') {
            $this->save_check(false);
        }

        $this->log->info("Up to statement {$this->number} (line {$this->line}); carrying on in the next task");

        return self::MORE;
    }

    /**
     * Every statement was checked or run
     */
    private function file_ended(): string {
        if ($this->import['status'] === 'checking') {
            $this->save_check(true);
            $this->log->info("Checked {$this->number} statements: {$this->run} to run, {$this->skipped} skipped");

            return self::ENDED;
        }

        $done = Platform_task::execute(
            $this->db,
            "UPDATE app_sql_import SET status = 'done', completed_at = NOW(), error = NULL WHERE id = ? AND status = 'running'",
            [(int) $this->import['id']],
        );

        if ($done !== 1) {
            $this->log->info('Stopped by the person who started it');

            return self::ENDED;
        }

        Platform_task::execute($this->db, 'DELETE FROM app_sql_import_chunk WHERE import_id = ?', [(int) $this->import['id']]);
        $this->log_outcome(null);
        $this->log->info("Imported {$this->import['file_name']}: {$this->run} statements ran, {$this->skipped} skipped");

        return self::ENDED;
    }

    /**
     * Save the check so far; once it is done, the summary and back to the
     * start of the file for running
     */
    private function save_check(bool $done): void {
        $summary = self::encode(['keywords' => $this->keywords, 'emptied' => array_values($this->emptied), 'skipped' => $this->skipped]);

        if (!$done) {
            Platform_task::execute(
                $this->db,
                "UPDATE app_sql_import SET position = ?, line = ?, statement_number = ?, statements_run = ?, statements_skipped = ?, summary = ? WHERE id = ? AND status = 'checking'",
                [$this->position, $this->line, $this->number, $this->run, $this->skipped, $summary, (int) $this->import['id']],
            );

            return;
        }

        Platform_task::execute(
            $this->db,
            "UPDATE app_sql_import
             SET status = 'checked', sql_bytes = ?, total_statements = ?, summary = ?,
                 position = 0, line = 1, statement_number = 0, statements_run = 0, statements_skipped = 0
             WHERE id = ? AND status = 'checking'",
            [$this->position, $this->run, $summary, (int) $this->import['id']],
        );
    }

    /**
     * Stop the import: the statement that failed or may not run, and where
     *
     * @param bool $can_continue whether Continue and Skip are offered (not when the check refused a statement)
     */
    private function fail(string $message, bool $can_continue): string {
        Platform_task::execute(
            $this->db,
            "UPDATE app_sql_import SET status = 'failed', error = ?, can_continue = ?, completed_at = NOW() WHERE id = ? AND status IN ('checking', 'running')",
            [$message, $can_continue ? 1 : 0, (int) $this->import['id']],
        );
        $this->log_outcome($message);
        $this->log->warn("The import stopped. {$message}");

        return self::ENDED;
    }

    /**
     * Record the outcome on the import's entry in the Database tab's History
     */
    private function log_outcome(?string $error): void {
        if (empty($this->import['log_id'])) {
            return;
        }

        Platform_task::execute(
            $this->db,
            'UPDATE app_database_log SET affected = ?, error = ? WHERE id = ?',
            [$this->run, $error === null ? null : mb_strcut($error, 0, 500), (int) $this->import['log_id']],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array {
        if ($json === '') {
            return [];
        }

        $value = json_decode($json, true);

        return is_array($value) ? $value : [];
    }

    private static function encode(array $value): string {
        return (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
