<?php

require_once APPPATH . 'modules/platform/live/Setup_sql_plan.php';
require_once APPPATH . 'modules/apps/traits/Sql_check_response.php';

/**
 * The new-app wizard's optional Database question: .sql files from the
 * repository (Setup_sql_plan::candidates, the likely ones ticked), another
 * file by path, and SQL typed in. They are read and checked when the form
 * is submitted, stored in app_setup_sql, and run once by the first
 * deploy, right after it creates the database (Platform_task::run_setup_sql).
 *
 * Part of the apps-setup_sql child module, used by App_form: Apps and
 * Onboarding both show the question and run its validation callback. The
 * using controller must also use Customer_guard and Provider_clients.
 */
trait Setup_sql_step {
    use Sql_check_response;

    /**
     * What _validate_setup_sql read and checked, for _save_setup_sql
     *
     * @var string|null
     */
    private ?string $setup_sql = null;

    /**
     * The Database question as posted, when the form is shown again after a
     * failed submit: the files that were offered, which were ticked, the
     * typed path and SQL
     *
     * @return array{sql_files: list<array{path: string, checked: bool}>, sql_files_loaded_for: string, sql_path: string, sql: string}
     */
    function _setup_sql_form(): array {
        $offered = post('sql_offered');
        $checked = $this->_posted_list('sql_files');
        $files = [];

        if (is_array($offered)) {
            foreach ($offered as $path) {
                $files[] = ['path' => (string) $path, 'checked' => in_array((string) $path, $checked, true)];
            }
        }

        return [
            'sql_files' => $files,
            'sql_files_loaded_for' => is_array($offered) ? $this->_posted_source_key() : '',
            'sql_path' => (string) post('sql_path', true),
            'sql' => (string) post('setup_sql'),
        ];
    }

    /**
     * MX partial: a checkbox for each .sql file on the branch
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

        try {
            $sql_files = Setup_sql_plan::candidates(array_keys($source['client']->tree($repository, $branch)));
        } catch (Client_Error | RuntimeException $e) {
            error_log("New app: could not list the .sql files of {$repository}@{$branch}: {$e->getMessage()}");
            $sql_files = [];
            $read_failed = true;
        }

        header('Content-Type: text/html');
        require APPPATH . 'modules/apps/setup_sql/views/_sql_file_options.php';
    }

    /**
     * POST: what _validate_setup_sql would refuse in the typed SQL, per
     * statement, checked while it is typed (apps_module/js/sql_check.js).
     * Nothing is stored or run.
     */
    function check_setup_sql(): void {
        $this->_make_sure_allowed();
        $this->_require_post_with_csrf();

        $this->_show_sql_check(Sql_check::setup((string) post('sql'))); // as typed, so the lines it names are the box's lines
    }

    /**
     * Validation callback for the Database question: reads the ticked files
     * and the typed path from the branch, and checks them and the typed SQL
     * the way they will run (Setup_sql_plan::plan). Keeps the result for
     * _save_setup_sql.
     */
    function _validate_setup_sql(string $value): bool|string {
        $this->setup_sql = null;
        $paths = $this->_posted_sql_paths();
        $typed = trim((string) post('setup_sql'));

        if ($paths === [] && $typed === '') {
            return true;
        }

        $sources = $this->_read_sql_files($paths);

        if (is_string($sources)) {
            return $sources;
        }

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
        $posted = post($name);

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
