<?php

require_once APPPATH . 'modules/platform/live/Setup_sql_plan.php';

/**
 * The new-app wizard's optional Database question: .sql files from the
 * repository (Setup_sql_plan::candidates, the likely ones ticked),
 * Trongate's default tables when the repository has no setup.sql of its own
 * (Setup_sql_plan::TRONGATE_TABLE_SETS, each ticked, with its SQL to edit,
 * run first, and an optional first administrator hashed by
 * Setup_sql::administrator), another file by path, and SQL typed in. They are read and checked when the form is submitted, stored in
 * app_setup_sql, and run once by the first deploy, right after it creates
 * the database (Platform_task::run_setup_sql).
 *
 * Part of the apps-setup_sql child module, used by App_form: Apps and
 * Onboarding both show the question and run its validation callback. The
 * using controller must also use Customer_guard and Provider_clients.
 */
trait Setup_sql_step {

    /**
     * What _validate_setup_sql read and checked, for _save_setup_sql
     *
     * @var string|null
     */
    private ?string $setup_sql = null;

    /**
     * The Database question as answered before (a failed submit, or the
     * wizard's draft, App_wizard_draft::_answer): the files that were offered, which were ticked,
     * Trongate's default tables as offered and edited, the typed path and SQL
     *
     * @return array{sql_files: list<array{path: string, checked: bool}>, sql_files_loaded_for: string, trongate_tables: list<array<string, mixed>>, administrator: array<string, string>|null, sql_path: string, sql: string}
     */
    function _setup_sql_form(): array {
        $offered = $this->_answer('sql_offered');
        $checked = $this->_posted_list('sql_files');
        $files = [];
        $loaded_for = '';

        // Something was offered, so the branch's files had been listed
        if (is_array($offered) || $this->_trongate_tables_offered()) {
            $loaded_for = $this->_posted_source_key();
        }

        if (is_array($offered)) {
            foreach ($offered as $path) {
                $files[] = ['path' => (string) $path, 'checked' => in_array((string) $path, $checked, true)];
            }
        }

        return [
            'sql_files' => $files,
            'sql_files_loaded_for' => $loaded_for,
            'trongate_tables' => $this->_posted_trongate_tables(),
            'administrator' => $this->_posted_administrator(),
            'sql_path' => (string) $this->_answer('sql_path', true),
            'sql' => (string) $this->_answer('setup_sql'),
        ];
    }

    /**
     * Partial: a checkbox for each .sql file on the branch, and one for
     * Trongate's default tables when the branch has no setup.sql
     */
    function sql_file_options(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $source = $this->_wizard_source_from_query((int) $customer->id);
        $branch = trim($_GET['branch'] ?? '');

        if ($source === null || $this->_validate_branch($branch) !== true) {
            http_response_code(422);
            die();
        }

        $repository = $source['repository'];
        $read_failed = false;
        $paths = [];

        try {
            $paths = array_keys($source['client']->tree($repository, $branch));
        } catch (Client_Error | RuntimeException $e) {
            error_log("New app: could not list the .sql files of {$repository}@{$branch}: {$e->getMessage()}");
            $read_failed = true;
        }

        $sql_files = Setup_sql_plan::candidates($paths);
        $trongate_tables = [];
        $administrator = null;

        if (Setup_sql_plan::offers_trongate_tables($paths)) {
            $trongate_tables = $this->_default_trongate_tables();
        }

        header('Content-Type: text/html');
        require APPPATH . 'modules/apps/setup_sql/views/_sql_file_options.php';
    }

    /**
     * Validation callback for the Database question: reads the ticked files
     * and the typed path from the branch, and checks them, Trongate's default
     * tables when ticked, and the typed SQL the way they will run
     * (Setup_sql_plan::plan). Keeps the result for _save_setup_sql.
     */
    function _validate_setup_sql(string $value): bool|string {
        $this->setup_sql = null;
        $paths = $this->_posted_sql_paths();
        $typed = trim((string) post('setup_sql'));
        $trongate_tables = $this->_ticked_trongate_tables_sql();
        $administrator = $this->_posted_administrator();

        if ($administrator !== null) {
            $refusal = $this->_administrator_refusal($administrator);

            if ($refusal !== null) {
                return $refusal;
            }

            $code = bin2hex(random_bytes(16));
            $trongate_tables['first administrator'] = Setup_sql_plan::administrator_sql($administrator['username'], $administrator['email'], $administrator['hash'], $code);
        }

        if ($paths === [] && $trongate_tables === [] && $typed === '') {
            return true;
        }

        $files = $this->_read_sql_files($paths);

        if (is_string($files)) {
            return $files;
        }

        // Trongate's default tables first, so a repository file that makes them too wins
        $sources = array_merge($trongate_tables, $files);

        if ($typed !== '') {
            $sources['typed SQL'] = $typed;
        }

        $statements = [];

        foreach ($sources as $name => $sql) {
            $plan = Setup_sql_plan::plan($sql);

            if ($plan['refusal'] !== null) {
                return $name . ', ' . lcfirst($plan['refusal']);
            }

            $statements = array_merge($statements, $plan['statements']);
        }

        if ($statements === []) {
            return 'The chosen SQL has no statements to run.';
        }

        $sql = Setup_sql_plan::join($statements);

        if (strlen($sql) > Setup_sql_plan::MAX_BYTES) {
            return 'That is more than ' . Setup_sql_plan::max_megabytes() . ' MB of SQL. Pick fewer files and import the rest from the Database tab.';
        }

        $this->setup_sql = $sql;

        return true;
    }

    /**
     * Whether the form offered Trongate's default tables
     */
    function _trongate_tables_offered(): bool {
        return $this->_answer('trongate_tables_offered') === '1';
    }

    /**
     * Trongate's default tables as first offered: every set ticked, with its
     * SQL from sql/{set}.sql
     *
     * @return list<array{set: string, label: string, tables: list<string>, checked: bool, sql_field: string, sql: string, adds_administrator: bool}>
     */
    function _default_trongate_tables(): array {
        $options = [];

        foreach (array_keys(Setup_sql_plan::TRONGATE_TABLE_SETS) as $set) {
            $sql = file_get_contents(APPPATH . 'modules/apps/setup_sql/sql/' . $set . '.sql');
            $options[] = $this->_trongate_tables_option($set, true, $sql);
        }

        return $options;
    }

    /**
     * Trongate's default tables as posted: which sets were ticked and their
     * edited SQL, or [] when the form did not offer them
     *
     * @return list<array{set: string, label: string, tables: list<string>, checked: bool, sql_field: string, sql: string, adds_administrator: bool}>
     */
    function _posted_trongate_tables(): array {
        if (!$this->_trongate_tables_offered()) {
            return [];
        }

        $ticked = $this->_ticked_trongate_sets();
        $options = [];

        foreach (array_keys(Setup_sql_plan::TRONGATE_TABLE_SETS) as $set) {
            $checked = in_array($set, $ticked, true);
            $sql = (string) $this->_answer($set . '_sql');
            $options[] = $this->_trongate_tables_option($set, $checked, $sql);
        }

        return $options;
    }

    /**
     * One set of Trongate's default tables, for views/_sql_file_options.php
     *
     * @return array{set: string, label: string, tables: list<string>, checked: bool, sql_field: string, sql: string, adds_administrator: bool}
     */
    function _trongate_tables_option(string $set, bool $checked, string $sql): array {
        $about = Setup_sql_plan::TRONGATE_TABLE_SETS[$set];

        return [
            'set' => $set,
            'label' => $about['label'],
            'tables' => $about['tables'],
            'checked' => $checked,
            'sql_field' => $set . '_sql',
            'sql' => $sql,
            'adds_administrator' => $set === 'trongate_administrators',
        ];
    }

    /**
     * The ticked sets' SQL as edited, by name, in the order they run
     *
     * @return array<string, string>
     */
    function _ticked_trongate_tables_sql(): array {
        $sources = [];

        foreach ($this->_posted_trongate_tables() as $option) {
            if ($option['checked'] && trim($option['sql']) !== '') {
                $sources['Trongate ' . lcfirst($option['label']) . ' tables'] = $option['sql'];
            }
        }

        return $sources;
    }

    /**
     * The first administrator the wizard posts (views/_administrator.php),
     * or null when none was added or the Administrators set is not ticked
     *
     * @return array{username: string, email: string, hash: string, hashed_with: string}|null
     */
    function _posted_administrator(): ?array {
        $hash = (string) $this->_answer('administrator_hash');

        if ($hash === '' || !in_array('trongate_administrators', $this->_ticked_trongate_sets(), true)) {
            return null;
        }

        return [
            'username' => (string) $this->_answer('administrator_username'),
            'email' => (string) $this->_answer('administrator_email'),
            'hash' => $hash,
            'hashed_with' => App_password::describe_value($hash),
        ];
    }

    /**
     * Why the posted first administrator cannot be added, or null
     *
     * @param array{username: string, email: string, hash: string} $administrator
     */
    function _administrator_refusal(array $administrator): ?string {
        if (!in_array('trongate_auth_core', $this->_ticked_trongate_sets(), true)) {
            return 'The first administrator needs the auth core tables (trongate_users). Tick them, or add the administrator later.';
        }

        $refusal = Setup_sql_plan::administrator_refusal($administrator['username'], $administrator['email'], $administrator['hash']);

        if ($refusal !== null) {
            return 'First administrator: ' . lcfirst($refusal);
        }

        return null;
    }

    /**
     * The Trongate table sets ticked on the posted form
     *
     * @return list<string>
     */
    function _ticked_trongate_sets(): array {
        if (!$this->_trongate_tables_offered()) {
            return [];
        }

        return $this->_posted_list('trongate_tables');
    }

    /**
     * The ticked files, then the typed path, each once
     *
     * @return list<string>
     */
    function _posted_sql_paths(): array {
        $paths = $this->_posted_list('sql_files');
        $typed_path = trim((string) post('sql_path', true), " /\t");

        if ($typed_path !== '') {
            $paths[] = $typed_path;
        }

        return array_values(array_unique($paths));
    }

    /**
     * A posted list of strings (name[]), or [] when it is missing
     *
     * @return list<string>
     */
    function _posted_list(string $name): array {
        $posted = $this->_answer($name);

        if (!is_array($posted)) {
            return [];
        }

        $list = [];

        foreach ($posted as $value) {
            if (is_string($value) && $value !== '') {
                $list[] = $value;
            }
        }

        return $list;
    }

    /**
     * The files' SQL from the chosen branch, by path, or why they cannot be read
     *
     * @param list<string> $paths
     * @return array<string, string>|string
     */
    function _read_sql_files(array $paths): array|string {
        if ($paths === []) {
            return [];
        }

        $customer = $this->_customer_or_redirect();
        $source = $this->_wizard_source((int) $customer->id, (string) post('repository', true), $this->_posted_public_link(), (string) post('provider', true));
        $branch = (string) post('repository_branch', true);

        // Nothing to read the files from; the repository and branch questions show why
        if ($source === null || $this->_validate_branch($branch) !== true) {
            return [];
        }

        $repository = $source['repository'];
        $client = $source['client'];

        try {
            $tree = $client->tree($repository, $branch);
        } catch (Client_Error | RuntimeException $e) {
            error_log("New app: could not list the files of {$repository}@{$branch}: {$e->getMessage()}");

            return 'We could not read ' . $repository . ' on GitHub to get the SQL files. Please try again.';
        }

        $files = [];

        foreach ($paths as $path) {
            if (!isset($tree[$path])) {
                return $path . ' is not in ' . $repository . ' on ' . $branch . '.';
            }

            try {
                $files[$path] = $client->blob($repository, $tree[$path]);
            } catch (Client_Error | RuntimeException $e) {
                error_log("New app: could not read {$path} of {$repository}@{$branch}: {$e->getMessage()}");

                return 'We could not read ' . $path . ' from GitHub. Please try again.';
            }
        }

        return $files;
    }

    /**
     * Store what _validate_setup_sql read, for the first deploy to run
     */
    function _save_setup_sql(int $app_id): void {
        if ($this->setup_sql === null) {
            return;
        }

        $this->model->set_setup_sql($app_id, Setup_sql_plan::encrypt($this->setup_sql));
    }
}
