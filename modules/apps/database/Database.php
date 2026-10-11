<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/apps/traits/App_page.php';
require_once APPPATH . 'modules/platform/live/App_database.php';
require_once APPPATH . 'modules/platform/live/App_password.php';
require_once __DIR__ . '/traits/App_database_guard.php';

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
    use App_database_guard;

    /** mx-build-modal for the Change password form (views/_password_form.php) */
    const PASSWORD_MODAL = '{"id":"db-password-modal","modalHeading":"Change password","width":"32rem","marginTop":"8vh"}';

    /**
     * apps/{id}/database: the tables, one table's rows (?table=, ?page=) and the SQL box.
     */
    function index(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $this->_database_page($app);
    }

    /**
     * POST apps/{id}/database/query: run the statements (in one transaction) and show their results.
     */
    function query(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $sql = trim((string) post('sql'));
        $allow_changes = (bool) post('allow_changes');
        $back_up = $allow_changes && (bool) post('backup') && App_database::statement_kind($sql) === 'write';
        $page = ['sql' => $sql];
        $refusal = $sql === '' ? null : App_database::refusal($sql, $allow_changes);

        if ($refusal !== null) {
            $page['query_error'] = $refusal; // refused before it reaches the database, so not logged
        } elseif ($sql !== '') {
            $database = $this->_open_app_database($app);

            if (is_string($database)) {
                $page['error'] = $database;
            } else {
                // The tables it names, whole; none for a CREATE TABLE of a new one
                $backup = !$back_up ? null : function () use ($app, $database, $sql): ?int {
                    $tables = App_database::tables_named_in($sql, $database->tables());

                    return $tables === [] ? null : $this->_save_backup($app, implode(', ', $tables), $database->back_up_tables($tables));
                };

                try {
                    $page['results'] = $this->_logged($app, 'query', $sql, $allow_changes, function () use ($database, $sql, $allow_changes) {
                        $results = $database->run($sql, $allow_changes);
                        $affected = array_filter(array_column($results, 'affected'), 'is_int');

                        return [$results, $affected === [] ? null : array_sum($affected)];
                    }, $backup, $page['backup_id']);
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

        $backup = null;

        if (!$is_new && post('backup')) {
            // A changed primary key moves the row: restoring removes it from there too
            $new_key = array_map('strval', array_intersect_key($values, $key) + $key);
            $backup = fn() => $this->_save_backup($app, $this->_describe_row($table, $key), $database->back_up_row($table, $key, $new_key));
        }

        try {
            $this->_logged($app, $is_new ? 'insert' : 'update', $this->_describe_row_change($is_new ? 'insert' : 'update', $table, $key, array_keys($values)), true, function () use ($database, $table, $key, $values, $is_new) {
                $affected = $is_new ? $database->insert($table, $values) : $database->update($table, $key, $values);

                return [$affected, $affected];
            }, $backup, $backup_id);
        } catch (PDOException | RuntimeException $e) {
            $this->_database_row_page($app, $table, $key, [
                'error' => $e instanceof PDOException ? $this->_database_error($e) : $e->getMessage(),
                'posted' => $values + $fields,
            ]);
            return;
        }

        set_flashdata($is_new ? 'Row added.' : ($backup_id !== null ? 'Row saved. How it was is under Backups.' : 'Row saved.'));
        redirect($this->_database_url($app, ['table' => $table]));
    }

    /**
     * apps/{id}/database/row/password?table=&key[col]=&column=: the Change password
     * form, for a Trongate MX modal (mx-build-modal). The choices come from the
     * cell's value as stored (App_password::choices).
     */
    function password_form(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $table = (string) ($_GET['table'] ?? '');
        $key = $this->_posted_key($_GET['key'] ?? null);
        [$database, $column] = $this->_password_cell_or_redirect($app, $table, $key, (string) ($_GET['column'] ?? ''));

        $this->_password_form($app, $table, $key, $column, $database->row($table, $key));
    }

    /**
     * POST apps/{id}/database/row/password/submit: hash a new password into one cell
     * (table, key[], column, password, password_confirm, algorithm, backup).
     * Answers with the table's URL, which the modal's form follows
     * (mx-redirect-on-success), or the form again with why it was refused.
     * The log names the column, never the password or the hash.
     */
    function change_password(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $table = (string) post('table');
        $key = $this->_posted_key(post('key'));
        [$database, $column] = $this->_password_cell_or_redirect($app, $table, $key, (string) post('column'));
        $name = $column['name'];

        $this->validation->set_rules('password', 'new password', 'required');
        $this->validation->set_rules('password_confirm', 'password confirmation', 'required|matches[password]');

        if ($this->validation->run() !== true) {
            $this->_password_form($app, $table, $key, $column, $database->row($table, $key)); // shows validation_errors()
            return;
        }

        $password = (string) post('password');
        $choice = (string) post('algorithm');
        $backup = !post('backup') ? null : fn() => $this->_save_backup($app, $this->_describe_row($table, $key), $database->back_up_row($table, $key));
        $current = null;
        $hash = '';

        // Every refusal (rate limit, lock, backup, hashing, MariaDB) comes back to the modal with its reason
        try {
            $row = $database->row($table, $key);

            if ($row === null) {
                throw new RuntimeException('That row is gone.');
            }

            $current = $row[$name];

            // Hashing inside _logged() keeps it under the customer's rate limit and lock
            $this->_logged($app, 'update', $this->_describe_row_change('update', $table, $key, [$name]) . ' -- new password hash', true, function () use ($database, $table, $key, $column, $password, $current, $choice, &$hash) {
                $hash = App_password::hash($password, $current, $choice);
                $too_long = App_password::too_long($hash, $column['column_type']);

                if ($too_long !== null) {
                    throw new RuntimeException($too_long);
                }

                $changed = $database->update($table, $key, [$column['name'] => $hash]);

                return [$changed, $changed];
            }, $backup, $backup_id);
        } catch (PDOException | RuntimeException $e) {
            $this->_password_form($app, $table, $key, $column, $current, $e instanceof PDOException ? $this->_database_error($e) : $e->getMessage());
            return;
        }

        set_flashdata(htmlspecialchars('New password saved in ' . $this->_describe_row($table, $key) . ', column ' . $name . ' (' . App_password::describe_hash($hash) . ').' . ($backup_id !== null ? ' The old hash is under Backups.' : '')));
        echo BASE_URL . $this->_database_url($app, ['table' => $table]);
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

        $backup = !post('backup') ? null : fn() => $this->_save_backup($app, $this->_describe_row($table, $key), $database->back_up_row($table, $key));

        try {
            $deleted = $this->_logged($app, 'delete', $this->_describe_row_change('delete', $table, $key), true, function () use ($database, $table, $key) {
                $deleted = $database->delete($table, $key);

                return [$deleted, $deleted];
            }, $backup, $backup_id);
            set_flashdata(match (true) {
                $deleted === 0 => 'That row was already gone.',
                $backup_id !== null => 'Row deleted. Its backup is under Backups.',
                default => 'Row deleted.',
            });
        } catch (PDOException | RuntimeException $e) {
            set_flashdata('Could not delete the row: ' . htmlspecialchars($e instanceof PDOException ? $this->_database_error($e) : $e->getMessage()));
        }

        redirect($this->_database_url($app, ['table' => $table]));
    }

    /**
     * POST apps/{id}/database/backups/restore: put back what a backup holds (backup).
     */
    function restore_backup(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        try {
            $restored = $this->_restore_backup($app, (int) post('backup'));
        } catch (PDOException | RuntimeException $e) {
            set_flashdata(htmlspecialchars('Could not restore: ' . ($e instanceof PDOException ? $this->_database_error($e) : $e->getMessage())));
            redirect($this->_database_url($app));
        }

        set_flashdata(htmlspecialchars('Restored ' . $restored['summary'] . ' as it was at ' . $restored['taken'] . ' UTC.'));
        redirect($this->_database_url($app, count($restored['tables']) === 1 ? ['table' => $restored['tables'][0]] : []));
    }

    // --- Helpers ---

    /**
     * Who App_database_guard::_logged() runs statements as
     */
    function _database_customer_id(): int {
        return (int) $this->_customer_or_redirect()->id;
    }

    /**
     * Render the database page. $page may carry sql, results, backup_id, query_error and error.
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
            'foreign_keys' => [],
            'where' => [],
            'rows' => [],
            'more' => false,
            'password_columns' => [],
            'password_urls' => [],
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'sql' => '',
            'results' => null,
            'query_error' => null,
            'error' => null,
            'recent_actions' => $this->model->recent_actions((int) $app->id),
            'backups' => $this->model->backups((int) $app->id),
            'backup_id' => null,
            'crumbs' => [...$this->_app_crumbs($app), ['apps/' . $app->id . '/database', 'Database']],
            'additional_includes_top' => ['apps_module/css/apps.css', 'apps-database_module/css/database.css'],
            'additional_includes_btm' => ['apps-database_module/js/password.js'],
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
                    $data['foreign_keys'] = $database->foreign_keys($table, $data['columns']);
                    // ?where[col]=value, from a foreign key that does not point at a primary key
                    $data['where'] = array_intersect_key($this->_posted_key($_GET['where'] ?? null), array_flip(array_column($data['columns'], 'name')));
                    $browse = $database->rows($table, $data['columns'], $data['page'], $data['where']);
                    $data['rows'] = $browse['rows'];
                    $data['more'] = $browse['more'];
                    $data['password_columns'] = $this->_password_columns($data['columns'], $data['rows']);
                    $data['password_urls'] = $this->_password_urls($app, $table, $data['columns'], $data['rows'], $data['password_columns']);
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

        $password_urls = [];

        if ($row !== null) {
            $password_columns = $this->_password_columns($columns, [$row]);
            $password_urls = $this->_password_urls($app, $table, $columns, [$row], $password_columns)[0] ?? [];
        }

        $data = [
            'view_module' => 'apps/database',
            'view_file' => 'row',
            'app' => $app,
            'table' => $table,
            'columns' => $columns,
            'foreign_keys' => $database->foreign_keys($table, $columns),
            'key' => $key,
            'row' => $extra['posted'] ?? $row,
            'password_urls' => $password_urls,
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
     * Columns whose cells offer Change password: text columns named like a
     * password column, or holding a password hash in one of $rows. None when
     * the table has no primary key, since a cell is picked by it.
     *
     * @param list<array{name: string, type: string, editable: bool, key: bool}> $columns
     * @param list<array<string, string|null>> $rows
     * @return list<string>
     */
    function _password_columns(array $columns, array $rows): array {
        if (App_database::primary_key($columns) === []) {
            return [];
        }

        $names = [];

        foreach ($columns as $column) {
            if ($this->_offers_password_change($column, array_column($rows, $column['name']))) {
                $names[] = $column['name'];
            }
        }

        return $names;
    }

    /**
     * @param array{name: string, type: string, editable: bool} $column
     * @param list<string|null> $values
     */
    function _offers_password_change(array $column, array $values): bool {
        if (!App_password::can_hold_hash($column)) {
            return false;
        }

        if (App_password::is_password_column($column)) {
            return true;
        }

        foreach ($values as $value) {
            if (App_password::is_hash($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Change password form's URL for each password cell of $rows, by row index then column
     *
     * @param list<array{name: string, key: bool}> $columns
     * @param list<array<string, string|null>> $rows
     * @param list<string> $password_columns
     * @return array<int, array<string, string>>
     */
    function _password_urls(object $app, string $table, array $columns, array $rows, array $password_columns): array {
        $primary_key = App_database::primary_key($columns);
        $urls = [];

        foreach ($rows as $i => $row) {
            $key = [];

            foreach ($primary_key as $name) {
                $key[$name] = (string) $row[$name];
            }

            foreach ($password_columns as $name) {
                $urls[$i][$name] = 'apps/' . $app->id . '/database/row/password?' . http_build_query(['table' => $table, 'key' => $key, 'column' => $name]);
            }
        }

        return $urls;
    }

    /**
     * The app's database and one cell's column, by primary key, or back to the table
     *
     * @param array<string, string> $key
     * @return array{0: App_database, 1: array{name: string, type: string, column_type: string, editable: bool}}
     */
    function _password_cell_or_redirect(object $app, string $table, array $key, string $name): array {
        [$database, $columns] = $this->_database_table_or_redirect($app, $table);
        $column = array_values(array_filter($columns, fn($c) => $c['name'] === $name))[0] ?? null;

        if ($column === null || !App_password::can_hold_hash($column) || !$this->_is_primary_key($key, $columns)) {
            redirect($this->_database_url($app, ['table' => $table]));
        }

        return [$database, $column];
    }

    /**
     * Render the Change password form (views/_password_form.php) on its own, for the modal
     *
     * @param array<string, string> $key
     * @param array{name: string, column_type: string} $column
     * @param array<string, string|null>|string|null $current the row, or the cell's value
     */
    function _password_form(object $app, string $table, array $key, array $column, array|string|null $current, ?string $error = null): void {
        $value = is_array($current) ? ($current[$column['name']] ?? null) : $current;

        $this->view('_password_form', [
            'base' => 'apps/' . $app->id . '/database',
            'table' => $table,
            'key' => $key,
            'column' => $column['name'],
            'choices' => App_password::choices($value, $column['column_type']),
            'error' => $error,
        ]);
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

    function _database_url(object $app, array $query = []): string {
        return 'apps/' . $app->id . '/database' . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
