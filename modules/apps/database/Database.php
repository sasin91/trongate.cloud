<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/apps/traits/App_page.php';
require_once APPPATH . 'modules/platform/live/App_database.php';
require_once APPPATH . 'modules/platform/live/App_password.php';
require_once __DIR__ . '/traits/App_database_guard.php';
require_once __DIR__ . '/traits/App_database_diagram.php';

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
    use App_database_diagram;

    /** mx-build-modal for the Change password form (views/_password_form.php) */
    const PASSWORD_MODAL = '{"id":"db-password-modal","modalHeading":"Change password","width":"32rem","marginTop":"8vh"}';

    /** mx-build-modal for confirming Delete selected (views/_delete_rows_form.php) */
    const DELETE_ROWS_MODAL = '{"id":"db-delete-rows-modal","modalHeading":"Delete rows","width":"28rem","marginTop":"12vh"}';

    /** mx-build-modal for editing a row (views/_row_form.php) */
    const ROW_MODAL = '{"id":"db-row-modal","modalHeading":"Edit row","width":"44rem","marginTop":"6vh"}';

    /** mx-build-modal for adding a row (views/_row_form.php) */
    const ADD_ROW_MODAL = '{"id":"db-row-modal","modalHeading":"Add row","width":"44rem","marginTop":"6vh"}';

    /** What the workspace shows (?view=); data is the default */
    const VIEWS = ['data', 'structure', 'diagram', 'sql', 'history', 'backups'];

    /** Tables kept open as tabs along the top of the workspace, per app */
    const OPEN_TABS = 6;

    /** Column types the grid aligns right */
    const NUMBER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'];

    /**
     * apps/{id}/database: the workspace. ?table= opens a table, ?view= picks
     * data, structure, sql, history or backups, ?close= closes a table's tab,
     * and the filter bar's ?filter_column=&filter_value= becomes ?where[col]=.
     */
    function index(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        if (isset($_GET['close'])) {
            $this->_close_tab_and_redirect($app, (string) $_GET['close']);
        }

        if (isset($_GET['filter_column'])) {
            $this->_filter_and_redirect($app);
        }

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
        $page = ['sql' => $sql, 'view' => 'sql'];
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
     * apps/{id}/database/row?table=&key[col]=: the form to edit one row, or to
     * add one without key[], for a Trongate MX modal (mx-build-modal). Opened
     * any other way, it shows the table filtered to that row.
     */
    function row(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $table = (string) ($_GET['table'] ?? '');
        $key = $this->_posted_key($_GET['key'] ?? null);

        if (!from_trongate_mx()) {
            redirect($this->_database_url($app, $key === [] ? ['table' => $table] : ['table' => $table, 'where' => $key]));
        }

        $this->_row_form($app, $table, $key);
    }

    /**
     * POST apps/{id}/database/row/submit: save the row form (table, key[], fields[], null[]).
     * Answers with the table's URL, which the modal's form follows
     * (mx-redirect-on-success), or 422 with JSON errors that MX shows in the form.
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
            echo validation_errors(422); // JSON that MX shows next to each field (highlight-errors)
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
        } catch (PDOException $e) {
            $this->_row_refused($this->_database_error($e));
            return;
        } catch (RuntimeException $e) {
            $this->_row_refused($e->getMessage());
            return;
        }

        set_flashdata($this->_row_saved_message($is_new, $backup_id));
        echo BASE_URL . $this->_database_url($app, $this->_table_page_query($table));
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
     * (mx-redirect-on-success), or 422 with JSON errors that MX shows in the form.
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
            echo validation_errors(422); // JSON that MX shows next to each field (highlight-errors)
            return;
        }

        $password = (string) post('password');
        $choice = (string) post('algorithm');
        $backup = null;

        if (post('backup')) {
            $backup = fn() => $this->_save_backup($app, $this->_describe_row($table, $key), $database->back_up_row($table, $key));
        }

        $statement = $this->_describe_row_change('update', $table, $key, [$name]) . ' -- new password hash';

        // Every refusal (rate limit, lock, backup, hashing, MariaDB) goes back to the modal with its reason.
        // Hashing runs inside _logged(), so it stays under the customer's rate limit and lock.
        try {
            $hash = $this->_logged($app, 'update', $statement, true, fn() => $this->_write_password_hash($database, $table, $key, $column, $password, $choice), $backup, $backup_id);
        } catch (PDOException $e) {
            $this->_password_refused($this->_database_error($e));
            return;
        } catch (RuntimeException $e) {
            $this->_password_refused($e->getMessage());
            return;
        }

        $message = 'New password saved in ' . $this->_describe_row($table, $key) . ', column ' . $name . ' (' . App_password::describe_value($hash) . ').';

        if ($backup_id !== null) {
            $message .= ' The old hash is under Backups.';
        }

        set_flashdata(htmlspecialchars($message));
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
     * apps/{id}/database/rows/delete/confirm?table=&count=: asks before Delete
     * selected, for a Trongate MX modal (mx-build-modal). Its button submits the
     * grid's form (form="db-rows-form"), which carries the ticked rows.
     */
    function delete_rows_form(): void {
        $this->_make_sure_allowed();
        $this->_member_app_or_redirect(segment(3, 'int'));

        $count = max(0, (int) ($_GET['count'] ?? 0));
        $rows = $count === 1 ? '1 row' : $count . ' rows';

        $this->view('_delete_rows_form', [
            'table' => (string) ($_GET['table'] ?? ''),
            'rows' => $rows,
        ]);
    }

    /**
     * POST apps/{id}/database/rows/delete?table=&page=&where[col]=: delete the
     * ticked rows (rows[], each a key[col]=value query string) in one
     * transaction, after one backup of them all when backup is ticked.
     * Back to the same page of the table.
     */
    function delete_rows(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $table = (string) ($_GET['table'] ?? '');
        $back_url = $this->_database_url($app, $this->_table_page_query($table));

        [$database, $columns] = $this->_database_table_or_redirect($app, $table);
        $keys = $this->_ticked_keys(post('rows'), $columns);

        if ($keys === []) {
            set_flashdata('Tick the rows to delete first.');
            redirect($back_url);
        }

        $backup = null;

        if (post('backup')) {
            $backup = fn() => $this->_save_backup($app, $this->_describe_rows($table, $keys), $database->back_up_rows($table, $keys));
        }

        $statement = $this->_describe_rows_delete($table, $keys);

        try {
            $deleted = $this->_logged($app, 'delete', $statement, true, function () use ($database, $table, $keys) {
                $deleted = $database->delete_rows($table, $keys);

                return [$deleted, $deleted];
            }, $backup, $backup_id);
        } catch (PDOException $e) {
            set_flashdata(htmlspecialchars('Nothing was deleted: ' . $this->_database_error($e)));
            redirect($back_url);
        } catch (RuntimeException $e) {
            set_flashdata(htmlspecialchars('Nothing was deleted: ' . $e->getMessage()));
            redirect($back_url);
        }

        set_flashdata($this->_rows_deleted_message(count($keys), $deleted, $backup_id));
        redirect($back_url);
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

    /**
     * POST apps/{id}/database/copy-tables: give a staging app's empty database
     * its production app's tables, without their rows.
     */
    function copy_tables(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        try {
            $count = $this->_copy_production_tables($app);
        } catch (PDOException | RuntimeException $e) {
            set_flashdata(htmlspecialchars('Could not copy the tables: ' . ($e instanceof PDOException ? $this->_database_error($e) : $e->getMessage())));
            redirect($this->_database_url($app));
        }

        set_flashdata('Copied ' . $count . ($count === 1 ? ' table' : ' tables') . ' from production, without their rows.');
        redirect($this->_database_url($app));
    }

    // --- Helpers ---

    /**
     * Create the production app's tables in this staging app's database,
     * which must have none yet. Logged like a statement from the SQL view.
     *
     * @return int how many tables were created
     * @throws RuntimeException when it is not a staging app, either database cannot be opened, or it has tables
     * @throws PDOException when MariaDB refuses a table
     */
    function _copy_production_tables(object $app): int {
        if ($app->environment !== 'staging') {
            throw new RuntimeException('Only a staging app copies its tables from production.');
        }

        $production = $this->model->find_project_app((int) $app->project_id, (int) $app->production_app_id);

        if ($production === false) {
            throw new RuntimeException('Its production app is gone.');
        }

        $source = $this->_open_app_database($production);

        if (is_string($source)) {
            throw new RuntimeException('Production: ' . $source);
        }

        $target = $this->_open_app_database($app);

        if (is_string($target)) {
            throw new RuntimeException($target);
        }

        if ($target->tables() !== []) {
            throw new RuntimeException('This database already has tables.');
        }

        $definitions = $source->table_definitions();
        $statement = implode(";\n\n", $definitions);

        $this->_logged($app, 'query', $statement, true, function () use ($target, $definitions) {
            $target->create_tables($definitions);

            return [null, null];
        });

        return count($definitions);
    }

    /**
     * Where to post copy_tables(): only on a staging app's database while it
     * has no tables (and opened fine)
     *
     * @param array<string, mixed> $data
     */
    function _copy_tables_url(object $app, array $data): ?string {
        if ($app->environment !== 'staging' || $data['error'] !== null || $data['tables'] !== []) {
            return null;
        }

        return $data['base'] . '/copy-tables';
    }

    /**
     * The primary keys of the ticked rows (rows[], each a key[col]=value query
     * string, as in the row's Edit link), each once. Anything that does not
     * name exactly the table's primary key is left out.
     *
     * @param list<array{name: string, key: bool}> $columns
     * @return list<array<string, string>>
     */
    function _ticked_keys(mixed $ticked, array $columns): array {
        if (!is_array($ticked)) {
            return [];
        }

        $keys = [];

        foreach ($ticked as $query) {
            if (!is_string($query)) {
                continue;
            }

            parse_str($query, $fields);
            $key = $this->_posted_key($fields['key'] ?? null);

            if ($this->_is_primary_key($key, $columns)) {
                $keys[$query] = $key;
            }
        }

        return array_values($keys);
    }

    /**
     * Rows as named in a backup: one row as _describe_row(), more by count
     *
     * @param list<array<string, string>> $keys
     */
    function _describe_rows(string $table, array $keys): string {
        if (count($keys) === 1) {
            return $this->_describe_row($table, $keys[0]);
        }

        return $table . ', ' . count($keys) . ' rows';
    }

    /**
     * Deleting rows as logged: one DELETE per row
     *
     * @param list<array<string, string>> $keys
     */
    function _describe_rows_delete(string $table, array $keys): string {
        $statements = [];

        foreach ($keys as $key) {
            $statements[] = $this->_describe_row_change('delete', $table, $key);
        }

        return implode(";\n", $statements);
    }

    function _rows_deleted_message(int $ticked, int $deleted, ?int $backup_id): string {
        $message = $deleted === 1 ? '1 row deleted.' : $deleted . ' rows deleted.';
        $gone = $ticked - $deleted;

        if ($gone === 1) {
            $message .= ' 1 was already gone.';
        }

        if ($gone > 1) {
            $message .= ' ' . $gone . ' were already gone.';
        }

        if ($backup_id !== null) {
            $message .= ' The backup is under Backups.';
        }

        return $message;
    }

    /**
     * ?table=&page=&where[col]= of the table page a form came from
     *
     * @return array<string, mixed>
     */
    function _table_page_query(string $table): array {
        $query = ['table' => $table];
        $page = (int) ($_GET['page'] ?? 1);
        $where = $this->_posted_key($_GET['where'] ?? null);

        if ($page > 1) {
            $query['page'] = $page;
        }

        if ($where !== []) {
            $query['where'] = $where;
        }

        return $query;
    }

    /**
     * Who App_database_guard::_logged() runs statements as
     */
    function _database_customer_id(): int {
        return (int) $this->_customer_or_redirect()->id;
    }

    /**
     * Render the workspace. $page may carry view, sql, results, backup_id, query_error and error.
     */
    function _database_page(object $app, array $page = []): void {
        $data = [
            'view_module' => 'apps/database',
            'view_file' => 'index',
            'app' => $app,
            ...$this->_app_header_data($app),
            'base' => 'apps/' . $app->id . '/database',
            'database_name' => Manifests::database_name((int) $app->id),
            'view' => $this->_requested_view(),
            'tables' => [],
            'table' => null,
            'columns' => [],
            'foreign_keys' => [],
            'where' => [],
            'rows' => [],
            'more' => false,
            'password_columns' => [],
            'password_urls' => [],
            'tick_values' => [],
            'delete_rows_url' => null,
            'delete_rows_confirm_url' => null,
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'sql' => '',
            'results' => null,
            'query_error' => null,
            'error' => null,
            'backup_id' => null,
            'diagram' => null,
            'crumbs' => [...$this->_app_crumbs($app), ['apps/' . $app->id . '/database', 'Database']],
            'additional_includes_top' => ['apps_module/css/apps.css', 'apps-database_module/css/database.css', 'apps-database_module/css/diagram.css'],
            'additional_includes_btm' => [
                'apps-database_module/js/password.js',
                'apps-database_module/js/keep_scroll.js',
                'apps-database_module/js/select.js',
                'apps-database_module/js/workspace.js',
            ],
        ];
        $data = $page + $data;

        $database = $data['error'] === null ? $this->_open_app_database($app) : $data['error'];

        if (is_string($database)) {
            $data['error'] = $database;
        } else {
            try {
                $data = $this->_load_table($app, $database, $data);
                $data = $this->_load_diagram($app, $database, $data);
            } catch (PDOException $e) {
                $data['error'] = $this->_database_error($e);
            }
        }

        $data = $this->_workspace_data($app, $data);
        $data['copy_tables_url'] = $this->_copy_tables_url($app, $data);

        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * The tables, and the open table's columns and page of rows (?table=, ?page=, ?where[col]=)
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    function _load_table(object $app, App_database $database, array $data): array {
        $data['tables'] = $database->tables();
        $table = (string) ($_GET['table'] ?? '');

        if (!isset($data['tables'][$table])) {
            return $data;
        }

        $this->_remember_open_table($app, $table);

        $data['table'] = $data['tables'][$table];
        $data['columns'] = $database->columns($table);
        $data['foreign_keys'] = $database->foreign_keys($table, $data['columns']);
        // ?where[col]=value, from the filter bar or a foreign key
        $data['where'] = array_intersect_key($this->_posted_key($_GET['where'] ?? null), array_flip(array_column($data['columns'], 'name')));

        if ($data['view'] !== 'data') {
            return $data;
        }

        $browse = $database->rows($table, $data['columns'], $data['page'], $data['where']);
        $data['rows'] = $browse['rows'];
        $data['more'] = $browse['more'];
        $data['password_columns'] = $this->_password_columns($data['columns'], $data['rows']);
        $data['password_urls'] = $this->_password_urls($app, $table, $data['columns'], $data['rows'], $data['password_columns']);
        $data['tick_values'] = $this->_tick_values($data['table'], $data['columns'], $data['rows']);
        $data['delete_rows_url'] = 'apps/' . $app->id . '/database/rows/delete?' . http_build_query($this->_table_page_query($table));
        $data['delete_rows_confirm_url'] = 'apps/' . $app->id . '/database/rows/delete/confirm?' . http_build_query(['table' => $table]);

        return $data;
    }

    /**
     * The Diagram view's values (App_database_diagram) when it is the view asked for
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    function _load_diagram(object $app, App_database $database, array $data): array {
        if ($data['view'] !== 'diagram' || $data['table'] === null) {
            return $data;
        }

        $diagram = $this->_diagram_data($app, $database, $data['tables'], $data['table']['name']);

        return $diagram + $data;
    }

    /**
     * Everything views/index.php shows, as plain values: the sidebar, the tabs,
     * the grid's header and cells, the status bar, the SQL results, history and backups
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    function _workspace_data(object $app, array $data): array {
        $table_name = $data['table']['name'] ?? null;

        $data['table_name'] = $table_name;
        $data['sidebar_tables'] = $this->_sidebar_tables($app, $data['tables'], $table_name, false);
        $data['sidebar_views'] = $this->_sidebar_tables($app, $data['tables'], $table_name, true);
        $data['open_tabs'] = $this->_workspace_tabs($app, $data['tables'], $table_name, $data['view']);
        $data['view_toggle'] = $this->_view_toggle($app, $table_name, $data['view']);
        $data['sql_url'] = $this->_database_url($app, $this->_with_table(['view' => 'sql'], $table_name));
        $data['history_url'] = $this->_database_url($app, $this->_with_table(['view' => 'history'], $table_name));
        $data['backups_url'] = $this->_database_url($app, $this->_with_table(['view' => 'backups'], $table_name));
        $data['query_url'] = $data['base'] . '/query' . ($table_name === null ? '' : '?' . http_build_query(['table' => $table_name]));
        $data['sql_placeholder'] = 'SELECT * FROM ' . ($table_name ?? 'users') . ' LIMIT 10';
        $data['statement_seconds'] = App_database::STATEMENT_SECONDS;
        $data['history'] = $this->_history_entries($app);
        $data['backup_entries'] = $this->_backup_entries($app, $data['backup_id']);
        $data['result_panels'] = $data['results'] === null ? [] : $this->_result_panels($data['results']);
        $data['results_summary'] = $data['results'] === null ? null : $this->_results_summary($data['results']);
        $data['backup_link'] = $data['backup_id'] === null ? null : $data['backups_url'] . '#backup-' . (int) $data['backup_id'];

        $data['grid_columns'] = [];
        $data['grid_rows'] = [];
        $data['structure'] = [];
        $data['filter'] = null;
        $data['status'] = null;

        if ($table_name === null) {
            return $data;
        }

        $data['filter'] = $this->_filter_bar($app, $table_name, $data['columns'], $data['where']);
        $data['structure'] = $this->_structure_rows($data['columns'], $data['foreign_keys']);
        $data['grid_columns'] = $this->_grid_columns($data['columns'], $data['foreign_keys']);
        $data['grid_rows'] = $this->_grid_rows($app, $table_name, $data);
        $data['status'] = $this->_status_bar($app, $data);

        return $data;
    }

    /**
     * ?view=, one of VIEWS; data when it is missing or unknown
     */
    function _requested_view(): string {
        $view = (string) ($_GET['view'] ?? 'data');

        if (!in_array($view, self::VIEWS, true)) {
            return 'data';
        }

        return $view;
    }

    /**
     * $query with table= added when a table is open
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    function _with_table(array $query, ?string $table_name): array {
        if ($table_name === null) {
            return $query;
        }

        return ['table' => $table_name] + $query;
    }

    /**
     * The sidebar's tables (or views): name, link, row estimate or size, and whether it is open
     *
     * @param array<string, array{name: string, view: bool, rows: int|null, bytes: int|null}> $tables
     * @return list<array{name: string, href: string, note: string, is_current: bool}>
     */
    function _sidebar_tables(object $app, array $tables, ?string $table_name, bool $views): array {
        $items = [];

        foreach ($tables as $table) {
            if ($table['view'] !== $views) {
                continue;
            }

            $items[] = [
                'name' => $table['name'],
                'href' => $this->_database_url($app, ['table' => $table['name']]),
                'note' => $table['rows'] === null ? '' : number_format($table['rows']),
                'is_current' => $table['name'] === $table_name,
            ];
        }

        return $items;
    }

    /**
     * The tabs along the top: the tables opened lately, then SQL
     *
     * @param array<string, array{name: string, view: bool}> $tables
     * @return list<array{href: string, label: string, icon: string, is_current: bool, close_href: string|null}>
     */
    function _workspace_tabs(object $app, array $tables, ?string $table_name, string $view): array {
        $tabs = [];
        $is_sql = in_array($view, ['sql', 'history', 'backups'], true);

        foreach ($this->_open_tables($app) as $name) {
            if (!isset($tables[$name])) {
                continue;
            }

            $close = ['close' => $name];

            if ($table_name !== null && $table_name !== $name) {
                $close['table'] = $table_name;
            }

            $tabs[] = [
                'href' => $this->_database_url($app, ['table' => $name]),
                'label' => $name,
                'icon' => $tables[$name]['view'] ? 'view' : 'table',
                'is_current' => !$is_sql && $name === $table_name,
                'close_href' => $this->_database_url($app, $close),
            ];
        }

        $tabs[] = [
            'href' => $this->_database_url($app, $this->_with_table(['view' => 'sql'], $table_name)),
            'label' => 'SQL',
            'icon' => 'sql',
            'is_current' => $is_sql,
            'close_href' => null,
        ];

        return $tabs;
    }

    /**
     * The Data | Structure switch in the status bar (phones add SQL)
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    function _view_toggle(object $app, ?string $table_name, string $view): array {
        if ($table_name === null) {
            return [];
        }

        return [
            [$this->_database_url($app, ['table' => $table_name]), 'Data', $view === 'data'],
            [$this->_database_url($app, ['table' => $table_name, 'view' => 'structure']), 'Structure', $view === 'structure'],
            [$this->_database_url($app, ['table' => $table_name, 'view' => 'diagram']), 'Diagram', $view === 'diagram'],
        ];
    }

    /**
     * Tables open as tabs for this app, oldest first, kept in the session
     *
     * @return list<string>
     */
    function _open_tables(object $app): array {
        $open = $_SESSION['database_tabs'][(int) $app->id] ?? [];

        if (!is_array($open)) {
            return [];
        }

        return array_values(array_filter($open, 'is_string'));
    }

    /**
     * Add a table to the open tabs, dropping the oldest past OPEN_TABS
     */
    function _remember_open_table(object $app, string $table): void {
        $open = $this->_open_tables($app);

        if (in_array($table, $open, true)) {
            return;
        }

        $open[] = $table;
        $_SESSION['database_tabs'][(int) $app->id] = array_slice($open, -self::OPEN_TABS);
    }

    /**
     * ?close=: close a table's tab, then show ?table= or the last tab still open
     */
    function _close_tab_and_redirect(object $app, string $closed): never {
        $open = array_values(array_diff($this->_open_tables($app), [$closed]));
        $_SESSION['database_tabs'][(int) $app->id] = $open;

        $table = (string) ($_GET['table'] ?? '');

        if ($table === '' && $open !== []) {
            $table = $open[count($open) - 1];
        }

        redirect($this->_database_url($app, $table === '' ? [] : ['table' => $table]));
    }

    /**
     * The filter bar's ?filter_column=&filter_value= as ?where[col]=, or no filter when the column is empty
     */
    function _filter_and_redirect(object $app): never {
        $table = (string) ($_GET['table'] ?? '');
        $column = (string) $_GET['filter_column'];
        $query = ['table' => $table];

        if ($column !== '') {
            $query['where'] = [$column => (string) ($_GET['filter_value'] ?? '')];
        }

        redirect($this->_database_url($app, $query));
    }

    /**
     * The filter bar: the columns to pick from, what is picked, and the filter in force
     *
     * @param list<array{name: string}> $columns
     * @param array<string, string> $where
     * @return array{columns: list<string>, column: string, value: string, active: list<string>, clear_href: string}
     */
    function _filter_bar(object $app, string $table_name, array $columns, array $where): array {
        $active = [];

        foreach ($where as $column => $value) {
            $active[] = $column . ' = ' . $value;
        }

        return [
            'columns' => array_column($columns, 'name'),
            'column' => (string) array_key_first($where),
            'value' => $where === [] ? '' : (string) reset($where),
            'active' => $active,
            'clear_href' => $this->_database_url($app, ['table' => $table_name]),
        ];
    }

    /**
     * The grid's header: each column's name, type, whether it is the key, and the tables it points at
     *
     * @param list<array{name: string, column_type: string, key: bool}> $columns
     * @param list<array{columns: list<string>, table: string}> $foreign_keys
     * @return list<array{name: string, type: string, is_key: bool, references: list<string>}>
     */
    function _grid_columns(array $columns, array $foreign_keys): array {
        $header = [];

        foreach ($columns as $column) {
            $header[] = [
                'name' => $column['name'],
                'type' => $column['column_type'],
                'is_key' => $column['key'],
                'references' => $this->_referenced_tables($column['name'], $foreign_keys),
            ];
        }

        return $header;
    }

    /**
     * Tables that a column's foreign keys point at
     *
     * @param list<array{columns: list<string>, table: string}> $foreign_keys
     * @return list<string>
     */
    function _referenced_tables(string $column, array $foreign_keys): array {
        $tables = [];

        foreach ($foreign_keys as $foreign_key) {
            if (in_array($column, $foreign_key['columns'], true)) {
                $tables[] = $foreign_key['table'];
            }
        }

        return $tables;
    }

    /**
     * The grid's rows: number, tick box value, the Edit row form's URL, and each cell
     *
     * @param array<string, mixed> $data
     * @return list<array{number: int, tick_value: string|null, edit_url: string|null, cells: list<array<string, mixed>>}>
     */
    function _grid_rows(object $app, string $table_name, array $data): array {
        $primary_key = App_database::primary_key($data['columns']);
        $first = ($data['page'] - 1) * App_database::PAGE_SIZE;
        $back_query = $this->_table_page_query($table_name);
        $grid = [];

        foreach ($data['rows'] as $i => $row) {
            $edit_url = null;

            if ($primary_key !== []) {
                $key = [];

                foreach ($primary_key as $name) {
                    $key[$name] = (string) $row[$name];
                }

                $edit_url = $data['base'] . '/row?' . http_build_query(['key' => $key] + $back_query);
            }

            $cells = [];

            foreach ($data['columns'] as $column) {
                $href = $this->_reference_href($app, $column['name'], $data['foreign_keys'], $row);
                $password_url = $data['password_urls'][$i][$column['name']] ?? null;
                $cells[] = $this->_grid_cell($row[$column['name']] ?? null, $column, $href, $password_url);
            }

            $grid[] = [
                'number' => $first + $i + 1,
                'tick_value' => $data['tick_values'][$i] ?? null,
                'edit_url' => $edit_url,
                'cells' => $cells,
            ];
        }

        return $grid;
    }

    /**
     * Where a cell's foreign key leads: the referenced table, filtered to the rows it points at
     *
     * @param list<array{columns: list<string>, table: string, referenced: list<string>, to_key: bool}> $foreign_keys
     * @param array<string, string|null> $row
     */
    function _reference_href(object $app, string $column, array $foreign_keys, array $row): ?string {
        foreach ($foreign_keys as $foreign_key) {
            if (!in_array($column, $foreign_key['columns'], true)) {
                continue;
            }

            $reference = App_database::reference($foreign_key, $row);

            if ($reference === null) {
                return null;
            }

            $values = $reference['key'] ?? $reference['where'] ?? [];

            return $this->_database_url($app, ['table' => $reference['table'], 'where' => $values]);
        }

        return null;
    }

    /**
     * One cell as the grid shows it: kind (null, bytes or text), its text, the
     * whole value as a tooltip when cut, and its link or Change password form
     *
     * @param array{name: string, type: string, key: bool}|null $column null for a SQL result
     * @return array{kind: string, text: string, title: string, href: string|null, password_url: string|null, class: string}
     */
    function _grid_cell(?string $value, ?array $column, ?string $href = null, ?string $password_url = null): array {
        $cell = ['kind' => 'text', 'text' => '', 'title' => '', 'href' => $href, 'password_url' => $password_url, 'class' => ''];

        if ($password_url !== null) {
            $cell['class'] = 'db-password-cell';
        } elseif ($column !== null && in_array($column['type'], self::NUMBER_TYPES, true)) {
            $cell['class'] = 'db-num';
        }

        if ($value === null) {
            $cell['kind'] = 'null';
            $cell['text'] = 'NULL';

            return $cell;
        }

        // rows() reads a binary column's size, not its bytes
        if ($column !== null && App_database::is_binary($column) && !$column['key']) {
            $cell['kind'] = 'bytes';
            $cell['text'] = (int) $value . ' bytes';

            return $cell;
        }

        if (!App_database::is_text($value)) {
            $cell['kind'] = 'bytes';
            $cell['text'] = strlen($value) . ' bytes';

            return $cell;
        }

        $cell['text'] = $this->_cut($value, 80);

        if ($cell['text'] !== $value) {
            $cell['title'] = $this->_cut($value, 1000);
        }

        return $cell;
    }

    /**
     * $text cut to $length characters, with an ellipsis when it was longer
     */
    function _cut(string $text, int $length): string {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . '…';
    }

    /**
     * $text on one line, cut to $length characters
     */
    function _one_line(string $text, int $length): string {
        return $this->_cut((string) preg_replace('/\s+/', ' ', $text), $length);
    }

    /**
     * The status bar under the grid: rows shown, the pager, + Row and why rows cannot be changed
     *
     * @param array<string, mixed> $data
     * @return array{summary: string, note: string|null, page: int, previous_href: string|null, next_href: string|null, add_row_url: string|null}
     */
    function _status_bar(object $app, array $data): array {
        $table = $data['table'];
        $shown = count($data['rows']);
        $summary = $shown === 1 ? '1 row' : $shown . ' rows';

        if ($table['rows'] !== null && $data['where'] === []) {
            $summary .= ' of about ' . number_format($table['rows']);
        }

        $note = null;

        if (!$table['view'] && App_database::primary_key($data['columns']) === []) {
            $note = 'No primary key, so change rows with SQL';
        }

        $query = ['table' => $table['name']];

        if ($data['where'] !== []) {
            $query['where'] = $data['where'];
        }

        $previous_href = null;
        $next_href = null;

        if ($data['page'] > 1) {
            $previous_href = $this->_database_url($app, $query + ['page' => $data['page'] - 1]);
        }

        if ($data['more']) {
            $next_href = $this->_database_url($app, $query + ['page' => $data['page'] + 1]);
        }

        $add_row_url = null;

        if (!$table['view']) {
            $add_row_url = $data['base'] . '/row?' . http_build_query($this->_table_page_query($table['name']));
        }

        return [
            'summary' => $summary,
            'note' => $note,
            'page' => $data['page'],
            'previous_href' => $previous_href,
            'next_href' => $next_href,
            'add_row_url' => $add_row_url,
        ];
    }

    /**
     * The Structure view: one line per column
     *
     * @param list<array{name: string, column_type: string, nullable: bool, key: bool, auto_increment: bool, default: string|null, generated: bool}> $columns
     * @param list<array{columns: list<string>, table: string, referenced: list<string>}> $foreign_keys
     * @return list<array{name: string, type: string, nullable: string, default: string|null, is_key: bool, extra: string, references: string}> default null shows as NULL
     */
    function _structure_rows(array $columns, array $foreign_keys): array {
        $rows = [];

        foreach ($columns as $column) {
            $extra = '';

            if ($column['auto_increment']) {
                $extra = 'auto_increment';
            }

            if ($column['generated']) {
                $extra = 'generated';
            }

            $rows[] = [
                'name' => $column['name'],
                'type' => $column['column_type'],
                'nullable' => $column['nullable'] ? 'yes' : 'no',
                'default' => $this->_default_text($column),
                'is_key' => $column['key'],
                'extra' => $extra,
                'references' => $this->_references_text($column['name'], $foreign_keys),
            ];
        }

        return $rows;
    }

    /**
     * A column's default for the Structure view: null for NULL, empty when it has none.
     * MariaDB writes a NULL default as the text NULL and quotes text defaults.
     *
     * @param array{nullable: bool, default: string|null} $column
     */
    function _default_text(array $column): ?string {
        if ($column['default'] !== null && $column['default'] !== 'NULL') {
            return $column['default'];
        }

        if ($column['nullable']) {
            return null;
        }

        return '';
    }

    /**
     * "companies.id" for each foreign key a column is part of
     *
     * @param list<array{columns: list<string>, table: string, referenced: list<string>}> $foreign_keys
     */
    function _references_text(string $column, array $foreign_keys): string {
        $targets = [];

        foreach ($foreign_keys as $foreign_key) {
            $position = array_search($column, $foreign_key['columns'], true);

            if ($position !== false) {
                $targets[] = $foreign_key['table'] . '.' . $foreign_key['referenced'][$position];
            }
        }

        return implode(', ', $targets);
    }

    /**
     * One panel per statement the SQL box ran: what it was, its outcome, and its rows
     *
     * @param list<array{statement: string, columns: list<string>, rows: list<array<int|string, string|null>>, affected: int|null, truncated: bool, seconds: float}> $results
     * @return list<array{statement: string, statement_short: string, outcome: string, columns: list<string>, rows: list<list<array<string, mixed>>>, shows_rows: bool}>
     */
    function _result_panels(array $results): array {
        $panels = [];

        foreach ($results as $result) {
            $rows = [];

            foreach ($result['rows'] as $row) {
                $cells = [];

                foreach ($row as $value) {
                    $cells[] = $this->_grid_cell($value, null);
                }

                $rows[] = $cells;
            }

            $panels[] = [
                'statement' => $result['statement'],
                'statement_short' => $this->_one_line($result['statement'], 120),
                'outcome' => $this->_result_outcome($result),
                'columns' => $result['columns'],
                'rows' => $rows,
                'shows_rows' => $result['affected'] === null,
            ];
        }

        return $panels;
    }

    /**
     * "3 rows changed in 0.01 s", or "12 rows in 0.01 s, only the first 500 shown"
     *
     * @param array{rows: list<mixed>, affected: int|null, truncated: bool, seconds: float} $result
     */
    function _result_outcome(array $result): string {
        $seconds = number_format($result['seconds'], 2) . ' s';

        if ($result['affected'] !== null) {
            $changed = $result['affected'] === 1 ? '1 row' : $result['affected'] . ' rows';

            return $changed . ' changed in ' . $seconds;
        }

        $count = count($result['rows']);
        $outcome = ($count === 1 ? '1 row' : $count . ' rows') . ' in ' . $seconds;

        if ($result['truncated']) {
            $outcome .= ', only the first ' . $count . ' shown';
        }

        return $outcome;
    }

    /**
     * "3 statements ran in one transaction in 0.04 s", or null for one statement
     *
     * @param list<array{seconds: float}> $results
     */
    function _results_summary(array $results): ?string {
        if (count($results) < 2) {
            return null;
        }

        return count($results) . ' statements ran in one transaction in ' . number_format(array_sum(array_column($results, 'seconds')), 2) . ' s';
    }

    /**
     * The History view: recent statements and row changes, newest first
     *
     * @return list<array{when: string, who: string, statement: string, statement_short: string, outcome: string, failed: bool, backup_href: string|null}>
     */
    function _history_entries(object $app): array {
        $entries = [];

        foreach ($this->model->recent_actions((int) $app->id) as $entry) {
            $entries[] = [
                'when' => substr((string) $entry->created_at, 0, 16),
                'who' => (string) ($entry->who ?? 'someone'),
                'statement' => (string) $entry->statement,
                'statement_short' => $this->_one_line((string) $entry->statement, 140),
                'outcome' => $this->_history_outcome($entry),
                'failed' => $entry->error !== null,
                'backup_href' => $entry->backup_id === null ? null : $this->_database_url($app, ['view' => 'backups']) . '#backup-' . (int) $entry->backup_id,
            ];
        }

        return $entries;
    }

    /**
     * What a logged action did: its error, rows changed or restored, or read
     */
    function _history_outcome(object $entry): string {
        if ($entry->error !== null) {
            return (string) $entry->error;
        }

        if ($entry->affected === null) {
            return 'read';
        }

        if ($entry->action === 'restore') {
            return (int) $entry->affected . ' restored';
        }

        return (int) $entry->affected . ' changed';
    }

    /**
     * The Backups view: each backup, what it holds, and the question Restore asks first
     *
     * @return list<array{id: int, taken: string, who: string, size: string, summary: string, statement: string|null, statement_short: string|null, failed: bool, restored: string|null, confirm: string, is_new: bool}>
     */
    function _backup_entries(object $app, ?int $new_backup_id): array {
        $entries = [];

        foreach ($this->model->backups((int) $app->id) as $backup) {
            $taken = substr((string) $backup->created_at, 0, 16);
            $rows = (int) $backup->rows === 1 ? '1 row' : number_format((int) $backup->rows) . ' rows';
            $statement = $backup->statement === null ? null : (string) $backup->statement;

            $entries[] = [
                'id' => (int) $backup->id,
                'taken' => $taken,
                'who' => (string) ($backup->who ?? 'someone'),
                'size' => $rows . ', ' . $this->_size_label((int) $backup->bytes),
                'summary' => (string) $backup->summary,
                'statement' => $statement,
                'statement_short' => $statement === null ? null : $this->_one_line($statement, 100),
                'failed' => $backup->error !== null,
                'restored' => $backup->restored_at === null ? null : substr((string) $backup->restored_at, 5, 11),
                'confirm' => 'Restore ' . $backup->summary . ' as it was at ' . $taken . ' UTC? Changes made to it since then are lost.',
                'is_new' => $new_backup_id !== null && (int) $backup->id === $new_backup_id,
            ];
        }

        return $entries;
    }

    /**
     * 2048 as "2.0 KB"
     */
    function _size_label(int $bytes): string {
        $size = (float) $bytes;

        foreach (['B', 'KB', 'MB'] as $unit) {
            if ($size < 1024) {
                return $unit === 'B' ? $bytes . ' B' : number_format($size, 1) . ' ' . $unit;
            }

            $size /= 1024;
        }

        return number_format($size, 1) . ' GB';
    }

    /**
     * Render the row form (views/_row_form.php) on its own, for the modal: $key empty adds a row
     *
     * @param array<string, string> $key
     */
    function _row_form(object $app, string $table, array $key): void {
        [$database, $columns] = $this->_database_table_or_redirect($app, $table);
        $back_query = $this->_table_page_query($table);
        $row = null;

        if ($key !== []) {
            if (!$this->_is_primary_key($key, $columns)) {
                redirect($this->_database_url($app, ['table' => $table]));
            }

            $row = $database->row($table, $key);

            if ($row === null) {
                echo '<p class="db-modal-gone">That row is gone. Close this and reload the table.</p>';
                return;
            }
        } elseif ($database->tables()[$table]['view']) {
            redirect($this->_database_url($app, ['table' => $table]));
        }

        $password_urls = [];

        if ($row !== null) {
            $password_columns = $this->_password_columns($columns, [$row]);
            $urls_by_row = $this->_password_urls($app, $table, $columns, [$row], $password_columns);
            $password_urls = $urls_by_row[0] ?? [];
        }

        $this->view('_row_form', [
            'base' => 'apps/' . $app->id . '/database',
            'table' => $table,
            'key' => $key,
            'back_query' => http_build_query($back_query),
            'fields' => $this->_row_fields($app, $columns, $database->foreign_keys($table, $columns), $row, $password_urls),
        ]);
    }

    /**
     * Each field of the row form, as plain values for views/_row_form.php
     *
     * @param list<array{name: string, column_type: string, nullable: bool, key: bool, auto_increment: bool, default: string|null, editable: bool, long: bool, type: string}> $columns
     * @param list<array{columns: list<string>, table: string, referenced: list<string>, to_key: bool}> $foreign_keys
     * @param array<string, string|null>|null $row null for a new row
     * @param array<string, string> $password_urls column => Change password form URL
     * @return list<array<string, mixed>>
     */
    function _row_fields(object $app, array $columns, array $foreign_keys, ?array $row, array $password_urls): array {
        $is_new = $row === null;
        $fields = [];

        foreach ($columns as $i => $column) {
            $name = $column['name'];
            $value = $row[$name] ?? null;
            $input = 'text';

            if (!$column['editable']) {
                $input = 'none';
            } elseif ($column['long'] || ($value !== null && (str_contains($value, "\n") || strlen($value) > 120))) {
                $input = 'textarea';
            }

            $fields[] = [
                'id' => 'db-field-' . $i,
                'name' => $name,
                'type' => $column['column_type'] . ($column['key'] ? ', key' : ''),
                'input' => $input,
                'value' => (string) $value,
                'is_null' => !$is_new && $value === null,
                'nullable' => $column['editable'] && $column['nullable'],
                'placeholder' => $this->_field_placeholder($column, $is_new),
                'read_only_text' => $this->_read_only_text($column, $value, $is_new),
                'wide' => $input === 'textarea' || strlen((string) $value) > 40,
                'password_url' => $password_urls[$name] ?? null,
                'reference_href' => $row === null ? null : $this->_reference_href($app, $name, $foreign_keys, $row),
                'references' => $this->_references_text($name, $foreign_keys),
            ];
        }

        return $fields;
    }

    /**
     * A new row's hint: automatic, or the column's default
     *
     * @param array{auto_increment: bool, default: string|null} $column
     */
    function _field_placeholder(array $column, bool $is_new): string {
        if (!$is_new) {
            return '';
        }

        if ($column['auto_increment']) {
            return 'automatic';
        }

        if ($column['default'] !== null) {
            return 'default: ' . $column['default'];
        }

        return '';
    }

    /**
     * What a field the form cannot change shows instead of an input
     *
     * @param array{type: string, key: bool} $column
     */
    function _read_only_text(array $column, ?string $value, bool $is_new): string {
        if ($is_new) {
            return 'Filled in by the database.';
        }

        if ($value === null) {
            return 'NULL';
        }

        if (!App_database::is_text($value)) {
            return strlen($value) . ' bytes (change it with SQL)';
        }

        if (App_database::is_binary($column)) {
            return $value . ' (change it with SQL)';
        }

        return $value;
    }

    /**
     * Answer the row form with why it was refused: the same 422 JSON as
     * validation_errors(422), which MX shows at the top of the form
     */
    function _row_refused(string $message): void {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['row' => [$message]], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Flash message after the row form saved
     */
    function _row_saved_message(bool $is_new, ?int $backup_id): string {
        if ($is_new) {
            return 'Row added.';
        }

        if ($backup_id !== null) {
            return 'Row saved. How it was is under Backups.';
        }

        return 'Row saved.';
    }

    /**
     * What each row's tick box sends (key[col]=value, as in its Edit link), by
     * row index. None for a view or a table without a primary key.
     *
     * @param array{view: bool} $table
     * @param list<array{name: string, key: bool}> $columns
     * @param list<array<string, string|null>> $rows
     * @return array<int, string>
     */
    function _tick_values(array $table, array $columns, array $rows): array {
        $primary_key = App_database::primary_key($columns);

        if ($table['view'] || $primary_key === []) {
            return [];
        }

        $values = [];

        foreach ($rows as $i => $row) {
            $key = [];

            foreach ($primary_key as $name) {
                $key[$name] = (string) $row[$name];
            }

            $values[$i] = http_build_query(['key' => $key]);
        }

        return $values;
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
     * Hash $password the $choice way for the cell's current value and store it.
     * Run by _logged(), which returns the hash.
     *
     * @param array<string, string> $key
     * @param array{name: string, column_type: string} $column
     * @return array{0: string, 1: int} the new hash, rows changed
     * @throws RuntimeException when the row is gone or the hash cannot be made or does not fit
     */
    function _write_password_hash(App_database $database, string $table, array $key, array $column, #[\SensitiveParameter] string $password, string $choice): array {
        $row = $database->row($table, $key);

        if ($row === null) {
            throw new RuntimeException('That row is gone.');
        }

        $hash = App_password::hash($password, $row[$column['name']], $choice);
        $too_long = App_password::too_long($hash, $column['column_type']);

        if ($too_long !== null) {
            throw new RuntimeException($too_long);
        }

        $changed = $database->update($table, $key, [$column['name'] => $hash]);

        return [$hash, $changed];
    }

    /**
     * Answer the Change password form with why it was refused: the same 422 JSON as
     * validation_errors(422), which MX shows under the password field
     */
    function _password_refused(string $message): void {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['password' => [$message]], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The app's database and one cell's column, by primary key, or back to the table
     *
     * @param array<string, string> $key
     * @return array{0: App_database, 1: array{name: string, type: string, column_type: string, editable: bool}}
     */
    function _password_cell_or_redirect(object $app, string $table, array $key, string $name): array {
        [$database, $columns] = $this->_database_table_or_redirect($app, $table);
        $back_url = $this->_database_url($app, ['table' => $table]);

        if (!$this->_is_primary_key($key, $columns)) {
            redirect($back_url);
        }

        foreach ($columns as $column) {
            if ($column['name'] === $name && App_password::can_hold_hash($column)) {
                return [$database, $column];
            }
        }

        redirect($back_url);
    }

    /**
     * Render the Change password form (views/_password_form.php) on its own, for the modal
     *
     * @param array<string, string> $key
     * @param array{name: string, column_type: string} $column
     * @param array<string, string|null>|null $row
     */
    function _password_form(object $app, string $table, array $key, array $column, ?array $row): void {
        $this->view('_password_form', [
            'base' => 'apps/' . $app->id . '/database',
            'table' => $table,
            'key' => $key,
            'column' => $column['name'],
            'choices' => App_password::choices($row[$column['name']] ?? null, $column['column_type']),
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
