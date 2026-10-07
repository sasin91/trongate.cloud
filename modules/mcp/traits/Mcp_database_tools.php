<?php

require_once APPPATH . 'modules/platform/live/App_database.php';
require_once APPPATH . 'modules/apps/database/traits/App_database_guard.php';

/**
 * MCP tools for an app's own database, with the Database tab's guardrails
 * (App_database_guard): the app's own database user, statements read-only
 * unless a write tool is used, the statement allowlist, limits, every
 * statement and row change in app_database_log ("Recent activity"), and a
 * backup before each change by default, which restore_backup puts back.
 *
 * Customer scope only: the log records who ran what. Registered by Mcp_tools.
 */
trait Mcp_database_tools {
    use App_database_guard;

    function _database_tools(): array {
        $app_id = ['type' => 'integer', 'minimum' => 1, 'description' => 'App id (from list_apps)'];
        $table = ['type' => 'string', 'minLength' => 1, 'description' => 'Table name (from list_tables)'];
        $key = ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number']], 'description' => 'The row\'s primary key: every primary key column => value (see describe_table)'];
        $values = ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'boolean', 'null']], 'description' => 'column => value; null stores NULL'];
        $sql = ['type' => 'string', 'minLength' => 1, 'maxLength' => App_database::MAX_STATEMENT_BYTES, 'description' => 'MariaDB statements separated by ";", run in one transaction'];
        $backup = ['type' => 'boolean', 'default' => true, 'description' => 'Back up what it changes first, so restore_backup can undo it'];
        $customer = ['customer'];

        return [
            [
                'name' => 'list_tables',
                'title' => 'List tables',
                'description' => "Tables and views in the app's database, with estimated row counts and sizes.",
                'inputSchema' => $this->_schema(['app_id' => $app_id], ['app_id']),
                'annotations' => $this->_hints(true),
                'scopes' => $customer,
            ],
            [
                'name' => 'describe_table',
                'title' => 'Describe table',
                'description' => 'A table\'s columns (type, nullable, default, auto increment) and its primary key, which picks rows for update_row and delete_row.',
                'inputSchema' => $this->_schema(['app_id' => $app_id, 'table' => $table], ['app_id', 'table']),
                'annotations' => $this->_hints(true),
                'scopes' => $customer,
            ],
            [
                'name' => 'browse_table',
                'title' => 'Browse table',
                'description' => 'One page of ' . App_database::PAGE_SIZE . ' rows, in primary key order. Long text is cut to ' . App_database::PREVIEW_CHARS . ' characters and binary columns show their size.',
                'inputSchema' => $this->_schema([
                    'app_id' => $app_id,
                    'table' => $table,
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                ], ['app_id', 'table']),
                'annotations' => $this->_hints(true),
                'scopes' => $customer,
            ],
            [
                'name' => 'query_database',
                'title' => 'Query database',
                'description' => 'Run SELECT, WITH, SHOW, DESCRIBE or EXPLAIN statements in a read-only transaction. At most ' . App_database::MAX_RESULT_ROWS . ' rows per statement and ' . App_database::STATEMENT_SECONDS . ' seconds in all.',
                'inputSchema' => $this->_schema(['app_id' => $app_id, 'sql' => $sql], ['app_id', 'sql']),
                'annotations' => $this->_hints(true),
                'scopes' => $customer,
            ],
            [
                'name' => 'execute_sql',
                'title' => 'Execute SQL',
                'description' => 'Run statements that change data or tables: INSERT, UPDATE, DELETE, REPLACE, CREATE, ALTER, DROP, TRUNCATE or RENAME (reads work too), in one transaction: when one fails, the data changes before it are rolled back (CREATE, ALTER, DROP, TRUNCATE and RENAME commit at once). The tables they name are backed up first unless backup is false. Prefer insert_row, update_row and delete_row for single rows. Show the user the SQL and confirm first.',
                'inputSchema' => $this->_schema(['app_id' => $app_id, 'sql' => $sql, 'backup' => $backup], ['app_id', 'sql']),
                'annotations' => $this->_hints(false, true),
                'scopes' => $customer,
            ],
            [
                'name' => 'insert_row',
                'title' => 'Insert row',
                'description' => 'Add one row. Left-out columns get their default (auto increment included).',
                'inputSchema' => $this->_schema(['app_id' => $app_id, 'table' => $table, 'values' => $values], ['app_id', 'table', 'values']),
                'annotations' => $this->_hints(false),
                'scopes' => $customer,
            ],
            [
                'name' => 'update_row',
                'title' => 'Update row',
                'description' => 'Change columns of the one row whose primary key is key. The row is backed up first unless backup is false.',
                'inputSchema' => $this->_schema(['app_id' => $app_id, 'table' => $table, 'key' => $key, 'values' => $values, 'backup' => $backup], ['app_id', 'table', 'key', 'values']),
                'annotations' => $this->_hints(false, true, true),
                'scopes' => $customer,
            ],
            [
                'name' => 'delete_row',
                'title' => 'Delete row',
                'description' => 'Delete the one row whose primary key is key. The row is backed up first unless backup is false.',
                'inputSchema' => $this->_schema(['app_id' => $app_id, 'table' => $table, 'key' => $key, 'backup' => $backup], ['app_id', 'table', 'key']),
                'annotations' => $this->_hints(false, true, true),
                'scopes' => $customer,
            ],
            [
                'name' => 'list_backups',
                'title' => 'List backups',
                'description' => "Backups taken before changes to the app's database (from the Database tab and from here), newest first, with the change each was taken for.",
                'inputSchema' => $this->_schema(['app_id' => $app_id], ['app_id']),
                'annotations' => $this->_hints(true),
                'scopes' => $customer,
            ],
            [
                'name' => 'restore_backup',
                'title' => 'Restore backup',
                'description' => 'Put back the tables or the row a backup holds, as they were when it was taken. Changes made since to those tables or that row are lost. Confirm with the user first.',
                'inputSchema' => $this->_schema(['app_id' => $app_id, 'backup_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'From list_backups']], ['app_id', 'backup_id']),
                'annotations' => $this->_hints(false, true),
                'scopes' => $customer,
            ],
            [
                'name' => 'database_activity',
                'title' => 'Database activity',
                'description' => "The latest statements and row changes on the app's database (from the Database tab and from here), newest first, with who made them.",
                'inputSchema' => $this->_schema(['app_id' => $app_id], ['app_id']),
                'annotations' => $this->_hints(true),
                'scopes' => $customer,
            ],
        ];
    }

    /**
     * Who App_database_guard::_logged() runs statements as
     */
    function _database_customer_id(): int {
        return (int) $this->customer->id;
    }

    function _tool_list_tables(array $args): array {
        [$app, $database] = $this->_app_database_or_fail($args);

        return ['database' => $database->name, 'tables' => array_values($database->tables())];
    }

    function _tool_describe_table(array $args): array {
        [$app, $database, $table, $columns] = $this->_app_table_or_fail($args);

        return [
            'table' => $table,
            'view' => $database->tables()[$table]['view'],
            'primary_key' => App_database::primary_key($columns),
            'columns' => array_map(fn(array $column) => [
                'name' => $column['name'],
                'type' => $column['column_type'],
                'nullable' => $column['nullable'],
                'default' => $column['default'],
                'auto_increment' => $column['auto_increment'],
                'editable' => $column['editable'],
            ], $columns),
        ];
    }

    function _tool_browse_table(array $args): array {
        [$app, $database, $table, $columns] = $this->_app_table_or_fail($args);
        $page = $this->_int_arg($args, 'page', false, 1);
        $browse = $this->_database_call(fn() => $database->rows($table, $columns, max(1, $page)));
        $binary = array_column(array_filter($columns, [App_database::class, 'is_binary']), 'name');

        return [
            'table' => $table,
            'page' => max(1, $page),
            'more' => $browse['more'],
            'binary_columns_show_bytes' => $binary,
            'rows' => array_map(fn(array $row) => $this->_text_row($row), $browse['rows']),
        ];
    }

    function _tool_query_database(array $args): array {
        return $this->_run_sql($args, false);
    }

    function _tool_execute_sql(array $args): array {
        return $this->_run_sql($args, true);
    }

    function _tool_insert_row(array $args): array {
        [$app, $database, $table, $columns] = $this->_app_table_or_fail($args);

        if ($database->tables()[$table]['view']) {
            throw new InvalidArgumentException("{$table} is a view; rows are added to its tables");
        }

        $values = $this->_row_values($args, $columns);
        $statement = $this->_describe_row_change('insert', $table, [], array_keys($values));

        $added = $this->_database_call(fn() => $this->_logged($app, 'insert', $statement, true, function () use ($database, $table, $values) {
            $added = $database->insert($table, $values);

            return [$added, $added];
        }));

        return ['inserted' => $added, 'statement' => $statement];
    }

    function _tool_update_row(array $args): array {
        [$app, $database, $table, $columns] = $this->_app_table_or_fail($args);
        $key = $this->_row_key($args, $columns);
        $values = $this->_row_values($args, $columns);

        if ($values === []) {
            throw new InvalidArgumentException('values must name at least one column');
        }

        if ($this->_database_call(fn() => $database->row($table, $key)) === null) {
            throw new RuntimeException('No row has that primary key');
        }

        $statement = $this->_describe_row_change('update', $table, $key, array_keys($values));
        $backup = null;
        $backup_id = null;

        if ($this->_bool_arg($args, 'backup', true)) {
            // A changed primary key moves the row: restoring removes it from there too
            $new_key = array_map('strval', array_intersect_key($values, $key) + $key);
            $backup = fn() => $this->_save_backup($app, $this->_describe_row($table, $key), $database->back_up_row($table, $key, $new_key));
        }

        $changed = $this->_database_call(function () use ($app, $statement, $database, $table, $key, $values, $backup, &$backup_id) {
            return $this->_logged($app, 'update', $statement, true, function () use ($database, $table, $key, $values) {
                $changed = $database->update($table, $key, $values);

                return [$changed, $changed];
            }, $backup, $backup_id);
        });

        return ['changed' => $changed, 'statement' => $statement, 'backup_id' => $backup_id, 'note' => $changed === 0 ? 'The row already had these values.' : null];
    }

    function _tool_delete_row(array $args): array {
        [$app, $database, $table, $columns] = $this->_app_table_or_fail($args);
        $key = $this->_row_key($args, $columns);
        $statement = $this->_describe_row_change('delete', $table, $key);
        $backup = !$this->_bool_arg($args, 'backup', true) ? null : fn() => $this->_save_backup($app, $this->_describe_row($table, $key), $database->back_up_row($table, $key));
        $backup_id = null;

        $deleted = $this->_database_call(function () use ($app, $statement, $database, $table, $key, $backup, &$backup_id) {
            return $this->_logged($app, 'delete', $statement, true, function () use ($database, $table, $key) {
                $deleted = $database->delete($table, $key);

                return [$deleted, $deleted];
            }, $backup, $backup_id);
        });

        return ['deleted' => $deleted, 'statement' => $statement, 'backup_id' => $backup_id, 'note' => $deleted === 0 ? 'That row was already gone.' : null];
    }

    function _tool_database_activity(array $args): array {
        [, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));

        return [
            'activity' => array_map(fn(object $entry) => [
                'action' => $entry->action,
                'statement' => $entry->statement,
                'affected' => $entry->affected === null ? null : (int) $entry->affected,
                'error' => $entry->error,
                'who' => $entry->who,
                'at' => $entry->created_at,
                'backup_id' => $entry->backup_id === null ? null : (int) $entry->backup_id,
            ], $this->model->recent_actions((int) $app->id, 20)),
        ];
    }

    function _tool_list_backups(array $args): array {
        [, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));

        return [
            'kept_days' => $this->model->backup_days(),
            'backups' => array_map(fn(object $backup) => [
                'id' => (int) $backup->id,
                'of' => $backup->summary,
                'rows' => (int) $backup->rows,
                'bytes' => (int) $backup->bytes,
                'taken_at' => $backup->created_at,
                'taken_by' => $backup->who,
                'before' => $backup->statement,
                'change_failed' => $backup->error,
                'restored_at' => $backup->restored_at,
                'expires_at' => $backup->expires_at,
            ], $this->model->backups((int) $app->id)),
        ];
    }

    function _tool_restore_backup(array $args): array {
        [, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $restored = $this->_database_call(fn() => $this->_restore_backup($app, $this->_int_arg($args, 'backup_id')));

        return ['restored' => $restored['summary'], 'as_it_was_at' => $restored['taken'] . ' UTC', 'tables' => $restored['tables']];
    }

    // --- Helpers ---

    /**
     * Run the sql argument through App_database::refusal() and the log
     */
    function _run_sql(array $args, bool $allow_changes): array {
        [$app, $database] = $this->_app_database_or_fail($args);
        $sql = trim($this->_string_arg($args, 'sql'));
        $refusal = App_database::refusal($sql, $allow_changes);

        if ($refusal !== null) {
            // The panel's wording names its checkbox; here it is a separate tool
            throw new InvalidArgumentException(str_replace('Tick "Allow changes" to run it.', 'Use execute_sql to run it.', $refusal));
        }

        // The tables it names, whole; none for a CREATE TABLE of a new one (as the Database tab does)
        $backup = !$allow_changes || !$this->_bool_arg($args, 'backup', true) || App_database::statement_kind($sql) !== 'write' ? null : function () use ($app, $database, $sql): ?int {
            $tables = App_database::tables_named_in($sql, $database->tables());

            return $tables === [] ? null : $this->_save_backup($app, implode(', ', $tables), $database->back_up_tables($tables));
        };
        $backup_id = null;

        $results = $this->_database_call(function () use ($app, $sql, $database, $allow_changes, $backup, &$backup_id) {
            return $this->_logged($app, 'query', $sql, $allow_changes, function () use ($database, $sql, $allow_changes) {
                $results = $database->run($sql, $allow_changes);
                $affected = array_filter(array_column($results, 'affected'), 'is_int');

                return [$results, $affected === [] ? null : array_sum($affected)];
            }, $backup, $backup_id);
        });

        return [
            'results' => array_map(fn(array $result) => [
                'statement' => $result['statement'],
                'columns' => $result['columns'],
                'rows' => array_map(fn(array $row) => $this->_text_row($row), $result['rows']),
                'truncated' => $result['truncated'],
                'affected' => $result['affected'],
                'seconds' => round($result['seconds'], 3),
            ], $results),
            'backup_id' => $backup_id,
        ];
    }

    /**
     * The app and its database
     *
     * @return array{object, App_database}
     */
    function _app_database_or_fail(array $args): array {
        [, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $database = $this->_open_app_database($app);

        if (is_string($database)) {
            throw new RuntimeException($database);
        }

        return [$app, $database];
    }

    /**
     * The app, its database, the table argument and the table's columns
     *
     * @return array{object, App_database, string, list<array>}
     */
    function _app_table_or_fail(array $args): array {
        [$app, $database] = $this->_app_database_or_fail($args);
        $table = $this->_string_arg($args, 'table');

        if (!isset($this->_database_call(fn() => $database->tables())[$table])) {
            throw new RuntimeException("No table named {$table}; see list_tables");
        }

        return [$app, $database, $table, $this->_database_call(fn() => $database->columns($table))];
    }

    /**
     * The key argument, which must name exactly the table's primary key columns
     *
     * @return array<string, string>
     */
    function _row_key(array $args, array $columns): array {
        $key = $args['key'] ?? null;

        if (!is_array($key) || array_filter($key, fn($value) => !is_scalar($value) || is_bool($value)) !== []) {
            throw new InvalidArgumentException('key must be an object of primary key column => value');
        }

        $key = array_map('strval', $key);

        if (!$this->_is_primary_key($key, $columns)) {
            $primary_key = App_database::primary_key($columns);

            throw new InvalidArgumentException($primary_key === []
                ? 'This table has no primary key, so single rows cannot be picked. Use execute_sql.'
                : 'key must name exactly the primary key columns: ' . implode(', ', $primary_key));
        }

        return $key;
    }

    /**
     * The values argument as the strings and NULLs App_database writes
     *
     * @return array<string, string|null>
     */
    function _row_values(array $args, array $columns): array {
        $values = $args['values'] ?? null;

        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new InvalidArgumentException('values must be an object of column => value');
        }

        $editable = array_column(array_filter($columns, fn(array $column) => $column['editable']), null, 'name');
        $row = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;

            if (!isset($editable[$name])) {
                throw new InvalidArgumentException("{$name} is not an editable column of this table (binary and generated columns cannot be written here)");
            }

            if ($value === null && !$editable[$name]['nullable']) {
                throw new InvalidArgumentException("{$name} cannot be NULL");
            }

            $row[$name] = match (true) {
                $value === null => null,
                is_bool($value) => $value ? '1' : '0',
                is_scalar($value) => (string) $value,
                default => throw new InvalidArgumentException("{$name} must be a string, number, boolean or null"),
            };
        }

        return $row;
    }

    /**
     * Row values safe for JSON: binary (not UTF-8) values become a size
     */
    function _text_row(array $row): array {
        return array_map(
            fn($value) => is_string($value) && !App_database::is_text($value) ? '(' . strlen($value) . ' bytes of binary data)' : $value,
            $row
        );
    }

    /**
     * Run $call; a database error becomes a tool error with MariaDB's message
     */
    function _database_call(callable $call): mixed {
        try {
            return $call();
        } catch (PDOException $e) {
            throw new RuntimeException($this->_database_error($e));
        }
    }
}
