<?php

require_once APPPATH . 'modules/platform/live/App_database.php';

/**
 * Controller side of the Database tab's guardrails, shared with the MCP tools:
 * open an app's database as the app's own user, and run statements and row
 * changes under the customer's rate limit and lock, recorded in app_database_log.
 *
 * The using controller defines _database_customer_id() (who the statements run
 * as), and its model uses App_database_log.
 */
trait App_database_guard {

    /**
     * Run $run (returning [its result, rows affected]) under the customer's
     * rate limit and lock, and record it in app_database_log, failed or not.
     *
     * @throws RuntimeException when the customer is over App_database_log::ACTIONS_PER_MINUTE
     *         or already has a statement running
     * @throws PDOException from $run, after it is logged
     */
    function _logged(object $app, string $action, string $statement, bool $allow_changes, callable $run): mixed {
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
            [$result, $entry['affected']] = $run();

            return $result;
        } catch (PDOException $e) {
            $entry['error'] = $this->_database_error($e);
            throw $e;
        } finally {
            $this->model->log_action($entry);
            $this->model->release_action_lock($customer_id);
        }
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
        return (string) preg_replace('/^SQLSTATE\[\w+\]: [^:]*: (\d+ )?/', '', $e->getMessage());
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
    }}
