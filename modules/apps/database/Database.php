<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/apps/traits/App_page.php';
require_once APPPATH . 'modules/platform/live/App_database.php';

/**
 * Child module of apps: apps/{id}/database, browse and edit an app's own
 * database (App_database). Every member of the app's project may use it,
 * like deploy and logs.
 *
 * @property-read Database_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Templates $templates
 * @property-read Validation $validation
 */
class Database extends Trongate {
    use Customer_guard;
    use App_page;

    /**
     * apps/{id}/database: the tables, one table's rows (?table=, ?page=) and the SQL box.
     */
    function index(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $this->_database_page($app);
    }

    /**
     * POST apps/{id}/database/query: run one statement and show its result.
     */
    function query(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $sql = trim((string) post('sql'));
        $allow_changes = (bool) post('allow_changes');
        $page = ['sql' => $sql];
        $refusal = $sql === '' ? null : App_database::refusal($sql, $allow_changes);

        if ($refusal !== null) {
            $page['query_error'] = $refusal; // refused before it reaches the database, so not logged
        } elseif ($sql !== '') {
            $database = $this->_open_app_database($app);

            if (is_string($database)) {
                $page['error'] = $database;
            } else {
                try {
                    $page['result'] = $this->_logged($app, 'query', $sql, $allow_changes, function () use ($database, $sql, $allow_changes) {
                        $result = $database->run($sql, $allow_changes);

                        return [$result, $result['affected']];
                    });
                } catch (PDOException | RuntimeException $e) {
                    $page['query_error'] = $e instanceof PDOException ? $this->_database_error($e) : $e->getMessage();
                }
            }
        }

        $this->_database_page($app, $page);
    }

    /**
     * apps/{id}/database/row?table=&key[col]=: edit one row, or add one without key[].
     */
    function row(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $this->_database_row_page($app, (string) ($_GET['table'] ?? ''), $this->_posted_key($_GET['key'] ?? null));
    }

    /**
     * POST apps/{id}/database/row/submit: save the row form (table, key[], fields[], null[]).
     */
    function submit_row(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $table = (string) post('table');
        $key = $this->_posted_key(post('key'));
        $fields = post('fields');
        $fields = is_array($fields) ? array_map('strval', array_filter($fields, 'is_scalar')) : [];
        $nulls = post('null');
        $nulls = is_array($nulls) ? $nulls : [];

        [$database, $columns] = $this->_database_table_or_redirect($app, $table);
        $is_new = $key === [];
        $values = [];

        if (!$is_new && !$this->_is_primary_key($key, $columns)) {
            redirect($this->_database_url($app, ['table' => $table]));
        }

        foreach ($columns as $column) {
            $name = $column['name'];

            if (!$column['editable'] || !array_key_exists($name, $fields)) {
                continue;
            }

            $value = str_replace("\r\n", "\n", $fields[$name]); // browsers send textarea lines as CRLF

            if ($column['nullable'] && !empty($nulls[$name]) && $value === '') {
                $values[$name] = null; // typing a value overrides a NULL left ticked
            } elseif ($is_new && $value === '' && ($column['auto_increment'] || $column['nullable'] || $column['default'] !== null)) {
                continue; // let the database fill it in
            } else {
                $values[$name] = $value;
            }

            // Bracket and dot names would read the wrong field; MariaDB still checks those
            $rules = is_string($values[$name] ?? null) ? App_database::rules($column) : '';

            if ($rules !== '' && strpbrk($name, '[].') === false) {
                $values[$name] = trim($value);
                $this->validation->set_rules('fields[' . $name . ']', $name, $rules);
            }
        }

        if ($this->validation->run() !== true) {
            $this->_database_row_page($app, $table, $key, ['posted' => $values + $fields]);
            return;
        }

        try {
            $this->_logged($app, $is_new ? 'insert' : 'update', $this->_describe_row_change($is_new ? 'insert' : 'update', $table, $key, array_keys($values)), true, function () use ($database, $table, $key, $values, $is_new) {
                $affected = $is_new ? $database->insert($table, $values) : $database->update($table, $key, $values);

                return [$affected, $affected];
            });
        } catch (PDOException | RuntimeException $e) {
            $this->_database_row_page($app, $table, $key, [
                'error' => $e instanceof PDOException ? $this->_database_error($e) : $e->getMessage(),
                'posted' => $values + $fields,
            ]);
            return;
        }

        set_flashdata($is_new ? 'Row added.' : 'Row saved.');
        redirect($this->_database_url($app, ['table' => $table]));
    }

    /**
     * POST apps/{id}/database/row/delete: delete one row (table, key[]).
     */
    function delete_row(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $table = (string) post('table');
        $key = $this->_posted_key(post('key'));

        [$database, $columns] = $this->_database_table_or_redirect($app, $table);

        if (!$this->_is_primary_key($key, $columns)) {
            redirect($this->_database_url($app, ['table' => $table]));
        }

        try {
            $deleted = $this->_logged($app, 'delete', $this->_describe_row_change('delete', $table, $key), true, function () use ($database, $table, $key) {
                $deleted = $database->delete($table, $key);

                return [$deleted, $deleted];
            });
            set_flashdata($deleted > 0 ? 'Row deleted.' : 'That row was already gone.');
        } catch (PDOException | RuntimeException $e) {
            set_flashdata('Could not delete the row: ' . htmlspecialchars($e instanceof PDOException ? $this->_database_error($e) : $e->getMessage()));
        }

        redirect($this->_database_url($app, ['table' => $table]));
    }

    // --- Helpers ---

    /**
     * Run $run (returning [its result, rows affected]) under the customer's
     * rate limit and lock, and record it in app_database_log, failed or not.
     *
     * @throws RuntimeException when the customer is over Database_model::ACTIONS_PER_MINUTE
     *         or already has a statement running
     * @throws PDOException from $run, after it is logged
     */
    function _logged(object $app, string $action, string $statement, bool $allow_changes, callable $run): mixed {
        $customer = $this->_customer_or_redirect();

        if ($this->model->over_action_limit((int) $customer->id)) {
            throw new RuntimeException('You ran ' . Database_model::ACTIONS_PER_MINUTE . ' statements in the last minute. Wait a moment, then try again.');
        }

        if (!$this->model->take_action_lock((int) $customer->id)) {
            throw new RuntimeException('Another statement of yours is still running. Wait for it to finish.');
        }

        $entry = [
            'app_id' => (int) $app->id,
            'customer_id' => (int) $customer->id,
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
            $this->model->release_action_lock((int) $customer->id);
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
     * Render the database page. $page may carry sql, result, query_error and error.
     */
    function _database_page(object $app, array $page = []): void {
        $data = [
            'view_module' => 'apps/database',
            'view_file' => 'index',
            'app' => $app,
            ...$this->_app_header_data($app),
            'database_name' => Manifests::database_name((int) $app->id),
            'tables' => [],
            'table' => null,
            'columns' => [],
            'rows' => [],
            'more' => false,
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'sql' => '',
            'result' => null,
            'query_error' => null,
            'error' => null,
            'recent_actions' => $this->model->recent_actions((int) $app->id),
            'crumbs' => [...$this->_app_crumbs($app), ['apps/' . $app->id . '/database', 'Database']],
            'additional_includes_top' => ['apps_module/css/apps.css', 'apps-database_module/css/database.css'],
        ];
        $data = $page + $data;

        $database = $data['error'] === null ? $this->_open_app_database($app) : $data['error'];

        if (is_string($database)) {
            $data['error'] = $database;
        } else {
            try {
                $data['tables'] = $database->tables();
                $table = (string) ($_GET['table'] ?? '');

                if (isset($data['tables'][$table])) {
                    $data['table'] = $data['tables'][$table];
                    $data['columns'] = $database->columns($table);
                    $browse = $database->rows($table, $data['columns'], $data['page']);
                    $data['rows'] = $browse['rows'];
                    $data['more'] = $browse['more'];
                }
            } catch (PDOException $e) {
                $data['error'] = $this->_database_error($e);
            }
        }

        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * Render the row form: $key empty adds a row. $extra may carry error and posted values.
     *
     * @param array<string, string> $key
     */
    function _database_row_page(object $app, string $table, array $key, array $extra = []): void {
        [$database, $columns] = $this->_database_table_or_redirect($app, $table);
        $row = null;

        if ($key !== []) {
            if (!$this->_is_primary_key($key, $columns)) {
                redirect($this->_database_url($app, ['table' => $table]));
            }

            $row = $database->row($table, $key);

            if ($row === null) {
                set_flashdata('That row is gone.');
                redirect($this->_database_url($app, ['table' => $table]));
            }
        } elseif ($database->tables()[$table]['view']) {
            redirect($this->_database_url($app, ['table' => $table]));
        }

        $data = [
            'view_module' => 'apps/database',
            'view_file' => 'row',
            'app' => $app,
            'table' => $table,
            'columns' => $columns,
            'key' => $key,
            'row' => $extra['posted'] ?? $row,
            'error' => $extra['error'] ?? null,
            'back_url' => $this->_database_url($app, ['table' => $table]),
            'crumbs' => [
                ...$this->_app_crumbs($app),
                ['apps/' . $app->id . '/database', 'Database'],
                [$this->_database_url($app, ['table' => $table]), $table],
            ],
            'additional_includes_top' => ['apps_module/css/apps.css', 'apps-database_module/css/database.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * The app's database and one of its tables' columns, or back to the database page
     *
     * @return array{0: App_database, 1: list<array{name: string, type: string, column_type: string, nullable: bool, key: bool, auto_increment: bool, default: string|null, editable: bool, long: bool}>}
     */
    function _database_table_or_redirect(object $app, string $table): array {
        $database = $this->_open_app_database($app);

        if (is_string($database)) {
            set_flashdata(htmlspecialchars($database));
            redirect($this->_database_url($app));
        }

        try {
            if (!isset($database->tables()[$table])) {
                redirect($this->_database_url($app));
            }

            return [$database, $database->columns($table)];
        } catch (PDOException $e) {
            set_flashdata(htmlspecialchars($this->_database_error($e)));
            redirect($this->_database_url($app));
        }
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
     * A primary key from key[col]=value request data
     *
     * @return array<string, string>
     */
    function _posted_key(mixed $key): array {
        if (!is_array($key)) {
            return [];
        }

        return array_map('strval', array_filter($key, fn($value, $column) => is_string($column) && is_scalar($value), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Validation callback for UNSIGNED integer columns (after the integer rule)
     */
    function _valid_db_unsigned(string $value): bool|string {
        return (int) $value >= 0 ? true : 'The [label] field cannot be negative.';
    }

    /**
     * Validation callback for DATETIME and TIMESTAMP: what MariaDB stores, seconds optional
     */
    function _valid_db_datetime(string $value): bool|string {
        foreach (['Y-m-d H:i:s.u', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $date = DateTime::createFromFormat('!' . $format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return true;
            }
        }

        return $value === '0000-00-00 00:00:00' ? true : 'The [label] field must be a date and time like 2026-10-06 21:04:10.';
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

    function _database_url(object $app, array $query = []): string {
        return 'apps/' . $app->id . '/database' . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
