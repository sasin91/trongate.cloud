<?php

require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once APPPATH . 'modules/platform/manifests/App_env_suggestions.php';
require_once APPPATH . 'modules/platform/manifests/Build_commands.php';
require_once __DIR__ . '/App_rules.php';
require_once __DIR__ . '/App_wizard_draft.php';
require_once APPPATH . 'modules/apps/setup_sql/traits/Setup_sql_step.php';
require_once APPPATH . 'modules/services/clients/Client.php';
require_once APPPATH . 'modules/services/clients/Github.php';

/**
 * New-app form shared by onboarding and the apps module:
 * repository picker data, validation rules/callbacks and app creation.
 * Repositories are those the customer's GitHub sign-in can see on the GitHub
 * App's installations, or a pasted link to a public GitHub repository (read
 * without a token, no sign-in needed; it deploys only when someone presses
 * Deploy). The app goes into the project the controller authorized.
 *
 * The using controller must also use Customer_guard and Provider_clients, and
 * its model must use Current_customer, Platform_actions and App_records.
 *
 * @property-read Validation $validation
 */
trait App_form {
    use App_rules;
    use App_wizard_draft;
    use Setup_sql_step;

    /** GitHub is the only source provider (Services::PROVIDERS) */
    const SOURCE_PROVIDER = 'github';

    /**
     * View data for the new-app questions. $connect_url signs the customer in
     * with GitHub and comes back to the form; without a sign-in they can
     * still paste a public link. A fresh open shows the draft kept under
     * $draft_key (App_wizard_draft), so a refresh keeps the answers, and
     * opens on $open_step (the step in the URL, '' for the first question).
     */
    function _app_form_data(int $customer_id, string $connect_url, string $draft_key, string $open_step): array {
        $this->_use_wizard_draft($draft_key);
        $repositories = $this->_pickable_repositories($customer_id);

        return [
            'provider' => self::SOURCE_PROVIDER,
            'github_connected' => $repositories !== false,
            'repositories' => $repositories === false ? [] : $repositories,
            'connect_url' => $connect_url,
            'public_link' => $this->_posted_public_link(),
            'selected_repository' => (string) $this->_answer('repository'),
            'repository_branch' => (string) $this->_answer('repository_branch'),
            'auto_deploy' => $this->_answer('auto_deploy') !== '0',
            'label' => (string) $this->_answer('label'),
            'domain' => (string) $this->_answer('domain'),
            'selected_type' => $this->_selected_type(),
            'install_url' => $this->services->github_app()->install_url(),
            'app_types' => self::APP_TYPES,
            'env_fields' => $this->_env_fields($this->_posted_env_suggestions()),
            'env_loaded_for' => $this->_posted_env_loaded_for(),
            'settings' => $this->_new_app_settings_form(),
            'setup_sql' => $this->_setup_sql_form(),
            'build' => $this->_build_form(),
            'error_step' => $this->_wizard_error_step(),
            'open_step' => $open_step,
        ];
    }

    /**
     * The Trongate version question's answer, the current release by default
     */
    function _selected_type(): string {
        $type = (string) $this->_answer('type');

        if ($type === '') {
            return 'trongate_v2';
        }

        return $type;
    }

    /**
     * The question the form opens on after a failed submit, when its error
     * came from a check that runs only on the server; '' lets app_steps.js pick
     */
    function _wizard_error_step(): string {
        $errors = $_SESSION['form_submission_errors'] ?? [];

        if (isset($errors['setup_sql'])) {
            return 'database';
        }

        if (isset($errors['build_commands']) || isset($errors['php_extensions'])) {
            return 'build';
        }

        return '';
    }

    /**
     * The repositories the customer may pick, or false when they have not
     * connected GitHub yet (or their sign-in has expired). None means the
     * GitHub App is not installed where they can see it yet.
     *
     * @return list<array{id: string, name: string, full_name: string, url: string, private: bool}>|false
     */
    function _pickable_repositories(int $customer_id): array|false {
        $provider = $this->_customer_provider($customer_id, 'source');

        if ($provider === false) {
            return false;
        }

        $client = $this->_provider_client($customer_id, $provider);

        // Its sign-in expired and could not be renewed (Services::for_customer forgot it)
        if ($this->_customer_provider($customer_id, 'source') === false) {
            return false;
        }

        try {
            return $client->list_repositories();
        } catch (Client_Error $e) {
            if ($e->getCode() === 401) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * The pasted link to a public repository, or '' when a repository was
     * picked from the list instead
     */
    function _posted_public_link(): string {
        return trim((string) $this->_answer('public_link', true));
    }

    /**
     * The last question's settings: the defaults a new app gets, or the
     * earlier answers (a failed submit or the draft)
     *
     * @return array{trongate_env: string, default_module: string, health_check_path: string, persistent_paths: string, process_name: string, process_command: string, always_on: bool, captures_mail: bool}
     */
    function _new_app_settings_form(): array {
        if (!$this->_has_answers()) {
            return [
                'trongate_env' => Runtime_settings::DEFAULT_TRONGATE_ENV,
                'default_module' => Runtime_settings::SUGGESTED_DEFAULT_MODULE,
                'health_check_path' => '',
                'persistent_paths' => '',
                'process_name' => '',
                'process_command' => '',
                'always_on' => false,
                'captures_mail' => true,
            ];
        }

        $process_names = (array) $this->_answer('process_name');
        $process_commands = (array) $this->_answer('process_command');

        return [
            'trongate_env' => (string) $this->_answer('trongate_env', true),
            'default_module' => (string) $this->_answer('default_module', true),
            'health_check_path' => (string) $this->_answer('health_check_path', true),
            'persistent_paths' => (string) $this->_answer('persistent_paths'),
            'process_name' => (string) ($process_names[0] ?? ''),
            'process_command' => (string) ($process_commands[0] ?? ''),
            'always_on' => $this->_answer('always_on') === '1',
            'captures_mail' => $this->_answer('captures_mail') !== '0',
        ];
    }

    /**
     * Validation::set_rules() saves its errors in the session before run()
     * checks the CSRF token. A submit without a form (a GET of the submit URL
     * from a reload or a browser preload) or with an expired token is sent away
     * by the CSRF check, but its "required" errors stay behind and showed up on
     * the next fresh New app page. Only a posted form is validated.
     */
    function _redirect_unless_posted(string $form_url): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            redirect($form_url);
        }
    }

    /**
     * A fresh open of the questions (a GET) shows no errors: errors belong to
     * the submit that re-renders this page in the same request.
     */
    function _forget_earlier_errors(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            unset($_SESSION['form_submission_errors'], $_SESSION['form_submission_name']);
        }
    }

    function _set_app_rules(): void {
        $this->validation->set_rules('provider', 'Source Control Provider', 'required|callback__validate_source_provider');

        if ($this->_posted_public_link() === '') {
            $this->validation->set_rules('repository', 'Repository', 'required|max_length[200]|callback__validate_pickable_repository');
        } else {
            $this->validation->set_rules('public_link', 'Public link', 'max_length[300]|callback__validate_public_link');
        }

        $this->validation->set_rules('repository_branch', 'Branch', 'required|max_length[100]|callback__validate_branch');
        $this->validation->set_rules('label', 'App Name', 'required|min_length[3]|max_length[50]');
        $this->validation->set_rules('domain', 'Custom Domain', 'max_length[253]|callback__validate_domain');
        $this->validation->set_rules('type', 'App Type', 'required|callback__validate_app_type');
        $this->validation->set_rules('env', 'Environment variables', 'callback__validate_suggested_env');
        $this->validation->set_rules('setup_sql', 'Database', 'callback__validate_setup_sql');
        $this->validation->set_rules('trongate_env', 'Environment', 'required|callback__validate_trongate_env');
        $this->validation->set_rules('default_module', 'Default module', 'callback__validate_default_module');
        $this->validation->set_rules('health_check_path', 'Health Check Path', 'max_length[200]|callback__validate_health_check_path');
        $this->validation->set_rules('php_extensions', 'PHP Extensions', 'max_length[255]|callback__validate_php_extensions');
        $this->validation->set_rules('build_commands', 'Build Commands', 'callback__validate_build_commands');
        $this->validation->set_rules('persistent_paths', 'Persistent Paths', 'max_length[4000]|callback__validate_persistent_paths');
        $this->validation->set_rules('processes', 'Processes', 'callback__validate_processes');
    }

    /**
     * Create the app from the validated POST data.
     * Slug and webhook secret are generated by App_records::create_app().
     */
    function _create_app_from_post(int $project_id, int $customer_id): int|false {
        $domain = $this->_normalise_domain(post('domain', true));
        $source = $this->_wizard_source($customer_id, (string) post('repository', true), $this->_posted_public_link(), (string) post('provider', true));

        if ($source === null) {
            return false;
        }

        // The switch posts a hidden 0 then the checkbox's 1; older forms leave it out
        $auto_deploy = post('auto_deploy') !== '0';

        // No webhook arrives for a repository the GitHub App is not installed on
        if ($this->_posted_public_link() !== '') {
            $auto_deploy = false;
        }

        $app_id = $this->model->create_app(
            project_id: $project_id,
            label: post('label', true),
            type: post('type', true),
            source_url: $source['client']->url($source['repository']),
            source_provider: self::SOURCE_PROVIDER,
            source_branch: post('repository_branch', true),
            domain: $domain,
            auto_deploy: $auto_deploy,
        );

        if ($app_id === false) {
            return false;
        }

        $this->_save_new_app_settings($project_id, $app_id, $domain);
        $this->_save_suggested_env($app_id);
        $this->_save_setup_sql($app_id);

        return $app_id;
    }

    /**
     * Save the last question's settings, the same ones the app's Settings page
     * changes. Its form posts the auto_deploy switch as a hidden 0 then a 1.
     */
    function _save_new_app_settings(int $project_id, int $app_id, ?string $domain): void {
        $this->model->update_app_settings($project_id, $app_id, [
            'domain' => $domain,
            'persistent_paths' => $this->_parse_persistent_paths((string) post('persistent_paths')),
            'php_extensions' => $this->_parse_php_extensions((string) post('php_extensions', true)),
            'build_commands' => (string) post('build_commands'),
            'always_on' => post('always_on') === '1',
            'auto_deploy' => post('auto_deploy') !== '0',
            'source_branch' => trim((string) post('repository_branch', true)),
            'trongate_env' => trim((string) post('trongate_env', true)),
            'default_module' => trim((string) post('default_module', true)),
            'captures_mail' => post('captures_mail') !== '0',
            'health_check_path' => $this->_parse_health_check_path((string) post('health_check_path', true)),
        ]);

        $this->model->add_app_processes($app_id, $this->_processes_from_post());
    }

    /**
     * Save the environment variables filled in on the Environment question
     * (POST env[NAME]); blank ones are skipped.
     */
    function _save_suggested_env(int $app_id): void {
        $posted = post('env');

        if (!is_array($posted)) {
            return;
        }

        $env = [];

        foreach ($posted as $name => $value) {
            if (is_string($value) && $value !== '') {
                $env[(string) $name] = $value;
            }
        }

        if ($env !== []) {
            $this->module('secrets');
            $this->secrets->save(App_env::MODULE, $app_id, App_env::SERVICE, $env);
        }
    }

    /**
     * Queue the first deployment of a new app. A failure to queue never
     * blocks app creation; the customer can press Deploy later.
     */
    function _queue_first_deploy(int $customer_id, int $app_id): int|false {
        $app = $this->model->find_member_app($customer_id, $app_id);

        if ($app === false) {
            return false;
        }

        try {
            return $this->model->queue_deploy(
                (int) $app->project_id,
                $app_id,
                $customer_id,
                'manual'
            );
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * The repository a wizard question reads and the client to read it with:
     * a pasted public link is read without a token, a picked repository with
     * the customer's GitHub sign-in. Null when the input is not valid.
     *
     * @return array{repository: string, client: Github}|null
     */
    function _wizard_source(int $customer_id, string $repository, string $public_link, string $provider): ?array {
        if ($public_link !== '') {
            $public_repository = Github::repository_from_link($public_link);

            if ($public_repository === null) {
                return null;
            }

            return ['repository' => $public_repository, 'client' => Github::anonymous()];
        }

        if ($this->_validate_source_provider($provider) !== true || $this->_validate_repository($repository) !== true) {
            return null;
        }

        return ['repository' => $repository, 'client' => $this->_provider_client($customer_id, $provider)];
    }

    /**
     * The wizard source in the query string of a question's partial
     *
     * @return array{repository: string, client: Github}|null
     */
    function _wizard_source_from_query(int $customer_id): ?array {
        $repository = trim($_GET['repository'] ?? '');
        $public_link = trim($_GET['public_link'] ?? '');
        $provider = trim($_GET['provider'] ?? '');

        return $this->_wizard_source($customer_id, $repository, $public_link, $provider);
    }

    /**
     * What the questions after the repository were read from, as app_steps.js
     * keys it: the pasted link or the picked repository, then the branch
     */
    function _posted_source_key(): string {
        $source = $this->_posted_public_link();

        if ($source === '') {
            $source = (string) $this->_answer('repository');
        }

        return $source . '@' . (string) $this->_answer('repository_branch');
    }

    /**
     * MX partial: <option>s for the branches of a repository.
     */
    function repository_branch_options(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $source = $this->_wizard_source_from_query((int) $customer->id);

        if ($source === null) {
            http_response_code(422);
            die();
        }

        $repository = $source['repository'];

        try {
            $branches = $source['client']->list_branches($repository, 100);
        } catch (Client_Error $e) {
            error_log("New app: could not list the branches of {$repository}: {$e->getMessage()}");
            http_response_code(422);
            die();
        }

        header('Content-Type: text/html');
        require APPPATH . 'modules/apps/views/_repository_branch_options.php';
    }

    /**
     * MX partial: an input for each environment variable the branch's
     * config/*.php reads (App_env_suggestions), prefilled with its default.
     */
    function env_suggestion_fields(): void {
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
            $files = $source['client']->config_php_files($repository, $branch, App_env_suggestions::MAX_FILES, App_env_suggestions::MAX_FILE_BYTES);
        } catch (Client_Error | RuntimeException $e) {
            $files = [];
            $read_failed = true;
        }

        $env_fields = $this->_env_fields(App_env_suggestions::from_files($files));

        header('Content-Type: text/html');
        require APPPATH . 'modules/apps/views/_env_suggestion_fields.php';
    }

    /**
     * MX partial: the Build question's fields, prefilled with what the
     * branch's root suggests (Build_commands::suggest)
     */
    function build_suggestion_fields(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $source = $this->_wizard_source_from_query((int) $customer->id);
        $branch = trim($_GET['branch'] ?? '');

        if ($source === null || $this->_validate_branch($branch) !== true) {
            http_response_code(422);
            die();
        }

        $repository = $source['repository'];

        try {
            $suggestion = $this->_build_suggestion($source['client'], $repository, $branch);
            $build = $this->_build_fields($suggestion['commands'], implode(', ', $suggestion['php_extensions']), $this->_build_found_note($suggestion));
        } catch (Client_Error | RuntimeException $e) {
            error_log("New app: could not read the root of {$repository}@{$branch} for build commands: {$e->getMessage()}");
            $build = $this->_build_fields('', '', 'We could not read the repository. Type the commands yourself, or add them later in Settings.');
        }

        header('Content-Type: text/html');
        require APPPATH . 'modules/apps/views/_build_fields.php';
    }

    /**
     * What the branch's root files suggest building with
     *
     * @return array{commands: string, php_extensions: list<string>, found: list<string>}
     */
    function _build_suggestion(Github $client, string $repository, string $branch): array {
        $root_files = $client->root_file_names($repository, $branch);
        $package_json = '';
        $composer_json = '';

        if (in_array('package.json', $root_files, true)) {
            $package_json = (string) $client->file_at($repository, $branch, 'package.json');
        }

        if (in_array('composer.json', $root_files, true)) {
            $composer_json = (string) $client->file_at($repository, $branch, 'composer.json');
        }

        return Build_commands::suggest($root_files, $package_json, $composer_json);
    }

    /**
     * The line above the Build question's fields: which files the suggestion came from
     *
     * @param array{commands: string, php_extensions: list<string>, found: list<string>} $suggestion
     */
    function _build_found_note(array $suggestion): string {
        if ($suggestion['found'] === []) {
            return 'Nothing to build found. Composer packages install on their own.';
        }

        $note = 'Read from ' . implode(' and ', $suggestion['found']) . '.';

        if ($suggestion['commands'] === '' && $suggestion['php_extensions'] === []) {
            $note .= ' There is no build script in package.json, so nothing runs.';
        }

        return $note;
    }

    /**
     * The Build question's view data
     *
     * @return array{commands: string, php_extensions: string, note: string}
     */
    function _build_fields(string $commands, string $php_extensions, string $note): array {
        return ['commands' => $commands, 'php_extensions' => $php_extensions, 'note' => $note];
    }

    /**
     * The Build question before a repository is read, or as answered before
     * (a failed submit, or the draft)
     *
     * @return array{commands: string, php_extensions: string, note: string, loaded_for: string}
     */
    function _build_form(): array {
        if (!$this->_has_answers()) {
            return $this->_build_fields('', '', 'Pick a repository and branch first.') + ['loaded_for' => ''];
        }

        $fields = $this->_build_fields((string) $this->_answer('build_commands'), (string) $this->_answer('php_extensions', true), '');

        return $fields + ['loaded_for' => $this->_build_loaded_for()];
    }

    /**
     * What the Build question's fields were read from. A failed submit had
     * them all; a draft says (app_steps.js sends build_loaded_for), as it can
     * be kept while they are still loading.
     */
    function _build_loaded_for(): string {
        if (REQUEST_TYPE === 'POST') {
            return $this->_posted_source_key();
        }

        return (string) $this->_answer('build_loaded_for');
    }

    /**
     * What the Environment question shows per variable. Blank ones are
     * likely secrets, so their input hides what is typed.
     *
     * @param list<array{name: string, default: string, file: string}> $suggestions
     * @return list<array{name: string, value: string, file: string, input_type: string}>
     */
    function _env_fields(array $suggestions): array {
        $fields = [];

        foreach ($suggestions as $suggestion) {
            $fields[] = [
                'name' => $suggestion['name'],
                'value' => $suggestion['default'],
                'file' => $suggestion['file'] === '' ? '' : 'config/' . $suggestion['file'],
                'input_type' => $suggestion['default'] === '' ? 'password' : 'text',
            ];
        }

        return $fields;
    }

    /**
     * The Environment question as posted, when the form is shown again after a failed submit
     *
     * @return list<array{name: string, default: string, file: string}>
     */
    function _posted_env_suggestions(): array {
        $posted = $this->_answer('env');

        if (!is_array($posted)) {
            return [];
        }

        $suggestions = [];

        foreach ($posted as $name => $value) {
            $suggestions[] = ['name' => (string) $name, 'default' => is_string($value) ? $value : '', 'file' => ''];
        }

        return $suggestions;
    }

    /**
     * The repository and branch the posted Environment question was read
     * from, so the form does not read them again and lose what was typed
     */
    function _posted_env_loaded_for(): string {
        if (!is_array($this->_answer('env'))) {
            return '';
        }

        return $this->_posted_source_key();
    }

    // --- Validation callbacks ---

    /**
     * The Environment question's env[NAME] values: names App_env allows, values within its size
     */
    function _validate_suggested_env(array|string $env): bool|string {
        if (!is_array($env)) {
            return true;
        }

        if (count($env) > App_env::MAX_VARIABLES) {
            return 'An app can have at most ' . App_env::MAX_VARIABLES . ' environment variables.';
        }

        foreach ($env as $name => $value) {
            $name_error = App_env::name_error((string) $name);

            if ($name_error !== null) {
                return $name_error;
            }

            if (!is_string($value) || strlen($value) > App_env::MAX_VALUE_BYTES) {
                return "The value of {$name} is too long.";
            }
        }

        return true;
    }

    /**
     * A repository the customer can see on one of the GitHub App's installations
     */
    function _validate_pickable_repository(string $repository): bool|string {
        $valid = $this->_validate_repository($repository);

        if ($valid !== true) {
            return $valid;
        }

        $provider = (string) post('provider', true);

        if ($this->_validate_source_provider($provider) !== true) {
            return 'Please choose a repository.';
        }

        $customer = $this->_customer_or_redirect();

        try {
            $pickable = $this->_provider_client((int) $customer->id, $provider)->can_pick($repository);
        } catch (Client_Error $e) {
            return 'We could not reach GitHub to check this repository. Please try again.';
        }

        return $pickable ? true : 'The trongate.cloud GitHub App cannot read this repository. Install it there first.';
    }

    /**
     * A link to a public GitHub repository. It is only read for owner/name;
     * every request goes to api.github.com, never to the pasted address.
     */
    function _validate_public_link(string $link): bool|string {
        $repository = Github::repository_from_link($link);

        if ($repository === null) {
            return 'Paste a link to a GitHub repository, like https://github.com/owner/name.';
        }

        try {
            $public = Github::anonymous()->is_public($repository);
        } catch (Client_Error $e) {
            return $this->_public_link_error($repository, $e);
        }

        if (!$public) {
            return $repository . ' is private. Connect GitHub and install the trongate.cloud app on it instead.';
        }

        return true;
    }

    /**
     * What to tell the customer when GitHub would not show the public repository
     */
    function _public_link_error(string $repository, Client_Error $e): string {
        $code = $e->getCode();

        if ($code === 404) {
            return 'We could not find a public repository at github.com/' . $repository . '. For a private repository, connect GitHub and install the trongate.cloud app on it.';
        }

        error_log("New app: could not check the public link to {$repository}: {$e->getMessage()}");

        if ($code === 403 || $code === 429) {
            return 'GitHub is limiting how often we may read public repositories right now. Please try again in a few minutes, or connect GitHub and pick the repository.';
        }

        return 'We could not reach GitHub to check this link. Please try again.';
    }
}
