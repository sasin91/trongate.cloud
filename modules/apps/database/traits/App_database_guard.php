<?php

require_once APPPATH . 'modules/platform/live/App_database.php';

/**
 * Controller side of the Database tab's guardrails, shared with the MCP tools:
 * open an app's database as the app's own user, and run statements and row
 * changes under the customer's rate limit and lock, recorded in app_database_log,
 * after an optional backup (app_database_backup) that can be restored.
 *
 * The using controller defines _database_customer_id() (who the statements run
 * as), and its model uses App_database_log.
 */
trait App_database_guard {

    /**
     * Run $run (returning [its result, rows affected]) under the customer's
     * rate limit and lock, and record it in app_database_log, failed or not.
     * $backup runs first (returning the id of the backup it saved, or null
     * when there was nothing to back up); when it fails, $run does not run.
     *
     * @param ?int $backup_id set to the backup's id
     * @throws RuntimeException when the customer is over App_database_log::ACTIONS_PER_MINUTE
     *         or already has a statement running, when the backup fails, or from $run
     * @throws PDOException from $run, after it is logged
     */
    function _logged(object $app, string $action, string $statement, bool $allow_changes, callable $run, ?callable $backup = null, ?int &$backup_id = null): mixed {
        $customer_id = $this->_database_customer_id();

        if ($this->model->over_action_limit($customer_id)) {
            throw new RuntimeException('You ran ' . $this->model->actions_per_minute() . ' statements in the last minute. Wait a moment, then try again.');
        }

        if (!$this->model->take_action_lock($customer_id)) {
            throw new RuntimeException('Another statement of yours is still running. Wait for it to finish.');
        }

        $entry = [
            'app_id' => (int) $app->id,
            'customer_id' => $customer_id,
            'action' => $action,
            'statement' => $statement,
            'allow_changes' => $allow_changes,
            'affected' => null,
            'error' => null,
        ];

        try {
            if ($backup !== null) {
                try {
                    $backup_id = $backup();
                } catch (PDOException | RuntimeException $e) {
                    throw new RuntimeException('Nothing was changed, because the backup failed: ' . ($e instanceof PDOException ? $this->_database_error($e) : $e->getMessage()) . ' Untick the backup to go ahead without one.');
                }
            }

            [$result, $entry['affected']] = $run();

            return $result;
        } catch (PDOException | RuntimeException $e) {
            $entry['error'] = $e instanceof PDOException ? $this->_database_error($e) : $e->getMessage();
            throw $e;
        } finally {
            $log_id = $this->model->log_action($entry);

            if ($backup_id !== null) {
                $this->model->link_backup($backup_id, $log_id);
            }

            $this->model->release_action_lock($customer_id);
        }
    }

    /**
     * Put back what one of the app's backups holds, logged as a 'restore'
     *
     * @return array{summary: string, taken: string, tables: list<string>}
     * @throws RuntimeException when the backup is gone or unreadable, the database cannot be opened, or from _logged()
     * @throws PDOException when restoring fails
     */
    function _restore_backup(object $app, int $backup_id): array {
        $backup = $this->model->backup((int) $app->id, $backup_id);

        if ($backup === false) {
            throw new RuntimeException('That backup is gone. Backups are kept for ' . $this->model->backup_days() . ' days.');
        }

        $tables = unserialize((string) $backup->manifest, ['allowed_classes' => false]);
        $database = $this->_open_app_database($app);

        if (is_string($database)) {
            throw new RuntimeException($database);
        }

        if (!is_array($tables)) {
            throw new RuntimeException('This backup cannot be read.');
        }

        $taken = substr((string) $backup->created_at, 0, 16);

        $this->_logged($app, 'restore', 'Restore backup #' . $backup->id . ' of ' . $backup->summary . ' taken ' . $taken, true, function () use ($database, $tables, $backup) {
            $database->restore($tables, (string) $backup->data);

            return [null, (int) $backup->rows];
        });
        $this->model->mark_backup_restored((int) $backup->id);

        return ['summary' => (string) $backup->summary, 'taken' => $taken, 'tables' => array_column($tables, 'name')];
    }

    /**
     * Store a backup of the app's database, taken by the current customer
     *
     * @param array{tables: list<array<string, mixed>>, data: string, rows: int, bytes: int} $backup
     */
    function _save_backup(object $app, string $summary, array $backup): int {
        return $this->model->save_backup((int) $app->id, $this->_database_customer_id(), $summary, $backup);
    }

    /**
     * A row as named in a backup: its table and primary key
     *
     * @param array<string, string> $key
     */
    function _describe_row(string $table, array $key): string {
        return $table . ' row ' . implode(', ', array_map(fn($column, $value) => $column . ' = ' . (App_database::is_text($value) ? $value : bin2hex($value)), array_keys($key), $key));
    }

    /**
     * A row change as logged: table, key and the columns written, never the values
     *
     * @param string $action insert, update or delete
     * @param array<string, string> $key
     * @param list<string> $columns
     */
    function _describe_row_change(string $action, string $table, array $key, array $columns = []): string {
        $where = implode(' AND ', array_map(fn($column, $value) => App_database::quote_name($column) . ' = ' . var_export($value, true), array_keys($key), $key));
        $set = implode(', ', array_map([App_database::class, 'quote_name'], $columns));

        return match ($action) {
            'insert' => 'INSERT INTO ' . App_database::quote_name($table) . ' (' . $set . ')',
            'delete' => 'DELETE FROM ' . App_database::quote_name($table) . ' WHERE ' . $where,
            default => 'UPDATE ' . App_database::quote_name($table) . ' SET ' . $set . ' WHERE ' . $where,
        };
    }

    /**
     * The app's database, or why it cannot be opened
     */
    function _open_app_database(object $app): App_database|string {
        if (empty($app->database_server_id)) {
            return 'This app has no database yet. It is created on the first deployment.';
        }

        $server = $this->model->database_server((int) $app->database_server_id);

        if ($server === false) {
            return 'The database server of this app is gone.';
        }

        try {
            return App_database::open((array) $server, (int) $app->id);
        } catch (PDOException $e) {
            return 'Could not reach the database: ' . $this->_database_error($e);
        }
    }

    /**
     * MariaDB's message without PDO's SQLSTATE prefix
     */
    function _database_error(PDOException $e): string {
        return App_database::error_message($e);
    }

    /**
     * Whether $key names exactly the table's primary key columns
     *
     * @param array<string, string> $key
     * @param list<array{name: string, key: bool}> $columns
     */
    function _is_primary_key(array $key, array $columns): bool {
        $names = array_keys($key);
        $primary_key = App_database::primary_key($columns);
        sort($names);
        sort($primary_key);

        return $key !== [] && $names === $primary_key;
    }
}
