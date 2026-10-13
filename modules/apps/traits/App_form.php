<?php

require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once APPPATH . 'modules/platform/manifests/App_env_suggestions.php';
require_once __DIR__ . '/App_rules.php';
require_once __DIR__ . '/App_setup_sql.php';

/**
 * New-app form shared by onboarding and the apps module:
 * repository picker data, validation rules/callbacks and app creation.
 * Repositories are those the customer's GitHub sign-in can see on the GitHub
 * App's installations; the app goes into the project the controller authorized.
 *
 * The using controller must also use Customer_guard and Provider_clients, and
 * its model must use Current_customer, Platform_actions and App_records.
 *
 * @property-read Validation $validation
 */
trait App_form {
    use App_rules;
    use App_setup_sql;

    /**
     * View data for the repository picker, or false when the customer has not
     * connected GitHub yet (or their sign-in has expired). No repositories
     * means the GitHub App is not installed where they can see it yet.
     */
    function _app_form_data(int $customer_id): array|false {
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
            $repositories = $client->list_repositories();
        } catch (Client_Error $e) {
            if ($e->getCode() === 401) {
                return false;
            }

            throw $e;
        }

        return [
            'provider' => $provider,
            'repositories' => $repositories,
            'install_url' => $this->services->github_app()->install_url(),
            'app_types' => self::APP_TYPES,
            'env_fields' => $this->_env_fields($this->_posted_env_suggestions()),
            'env_loaded_for' => $this->_posted_env_loaded_for(),
            'settings' => $this->_new_app_settings_form(),
            'setup_sql' => $this->_setup_sql_form(),
        ];
    }

    /**
     * The last question's settings: the defaults a new app gets, or what was
     * posted when the form is shown again after a failed submit
     *
     * @return array{trongate_env: string, health_path: string, php_extensions: string, persistent_paths: string, process_name: string, process_command: string, always_on: bool, mail_capture: bool}
     */
    function _new_app_settings_form(): array {
        if (REQUEST_TYPE !== 'POST') {
            return [
                'trongate_env' => Trongate_config::DEFAULT_ENV,
                'health_path' => '',
                'php_extensions' => '',
                'persistent_paths' => '',
                'process_name' => '',
                'process_command' => '',
                'always_on' => false,
                'mail_capture' => true,
            ];
        }

        $process_names = (array) post('process_name');
        $process_commands = (array) post('process_command');

        return [
            'trongate_env' => (string) post('trongate_env', true),
            'health_path' => (string) post('health_path', true),
            'php_extensions' => (string) post('php_extensions', true),
            'persistent_paths' => (string) post('persistent_paths'),
            'process_name' => (string) ($process_names[0] ?? ''),
            'process_command' => (string) ($process_commands[0] ?? ''),
            'always_on' => post('always_on') === '1',
            'mail_capture' => post('mail_capture') !== '0',
        ];
    }

    function _set_app_rules(): void {
        $this->validation->set_rules('provider', 'Source Control Provider', 'required|callback__validate_source_provider');
        $this->validation->set_rules('repository', 'Repository', 'required|max_length[200]|callback__validate_pickable_repository');
        $this->validation->set_rules('repository_branch', 'Branch', 'required|max_length[100]|callback__validate_branch');
        $this->validation->set_rules('label', 'App Name', 'required|min_length[3]|max_length[50]');
        $this->validation->set_rules('domain', 'Custom Domain', 'max_length[253]|callback__validate_domain');
        $this->validation->set_rules('type', 'App Type', 'required|callback__validate_app_type');
        $this->validation->set_rules('env', 'Environment variables', 'callback__validate_suggested_env');
        $this->validation->set_rules('setup_sql', 'Database', 'callback__validate_setup_sql');
        $this->validation->set_rules('trongate_env', 'Environment', 'required|callback__validate_trongate_env');
        $this->validation->set_rules('health_path', 'Health Check Path', 'max_length[200]|callback__validate_health_path');
        $this->validation->set_rules('php_extensions', 'PHP Extensions', 'max_length[255]|callback__validate_php_extensions');
        $this->validation->set_rules('persistent_paths', 'Persistent Paths', 'max_length[4000]|callback__validate_persistent_paths');
        $this->validation->set_rules('processes', 'Processes', 'callback__validate_processes');
    }

    /**
     * Create the app from the validated POST data.
     * Slug and webhook secret are generated by App_records::create_app().
     */
    function _create_app_from_post(int $project_id, int $customer_id): int|false {
        $provider = post('provider', true);
        $domain = $this->_normalise_domain(post('domain', true));

        $client = $this->_provider_client($customer_id, $provider);

        $app_id = $this->model->create_app(
            project_id: $project_id,
            label: post('label', true),
            type: post('type', true),
            source_url: $client->url(post('repository', true)),
            source_provider: $provider,
            source_branch: post('repository_branch', true),
            domain: $domain,
            // The switch posts a hidden 0 then the checkbox's 1; older forms leave it out
            auto_deploy: post('auto_deploy') !== '0',
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
            'php_extensions' => implode(',', $this->_parse_php_extensions((string) post('php_extensions', true))),
            'always_on' => post('always_on') === '1',
            'auto_deploy' => post('auto_deploy') !== '0',
            'source_branch' => trim((string) post('repository_branch', true)),
            'trongate_env' => trim((string) post('trongate_env', true)),
            'mail_capture' => post('mail_capture') !== '0',
            'health_path' => $this->_parse_health_path((string) post('health_path', true)),
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
     * MX partial: <option>s for the branches of a repository.
     */
    function repository_branch_options(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $repository = trim($_GET['repository'] ?? '');
        $provider = trim($_GET['provider'] ?? '');

        if ($this->_validate_source_provider($provider) !== true || $this->_validate_repository($repository) !== true) {
            http_response_code(422);
            die();
        }

        $client = $this->_provider_client((int) $customer->id, $provider);
        $branches = $client->list_branches($repository, 100);

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
        $repository = trim($_GET['repository'] ?? '');
        $branch = trim($_GET['branch'] ?? '');
        $provider = trim($_GET['provider'] ?? '');

        if ($this->_validate_source_provider($provider) !== true
            || $this->_validate_repository($repository) !== true
            || $this->_validate_branch($branch) !== true) {
            http_response_code(422);
            die();
        }

        $client = $this->_provider_client((int) $customer->id, $provider);
        $read_failed = false;

        try {
            $files = $client->config_php_files($repository, $branch, App_env_suggestions::MAX_FILES, App_env_suggestions::MAX_FILE_BYTES);
        } catch (Client_Error | RuntimeException $e) {
            $files = [];
            $read_failed = true;
        }

        $env_fields = $this->_env_fields(App_env_suggestions::from_files($files));

        header('Content-Type: text/html');
        require APPPATH . 'modules/apps/views/_env_suggestion_fields.php';
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
        $posted = post('env');

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
        if (!is_array(post('env'))) {
            return '';
        }

        return (string) post('repository') . '@' . (string) post('repository_branch');
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
}
