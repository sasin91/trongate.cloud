<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/services/traits/Provider_clients.php';
require_once __DIR__ . '/traits/App_form.php';
require_once __DIR__ . '/traits/App_page.php';
require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once APPPATH . 'modules/platform/manifests/App_env.php';
require_once APPPATH . 'modules/platform/live/App_log_rates.php';
require_once APPPATH . 'modules/platform/mail/Mail_capture.php';

/**
 * Apps inside a project: create, overview, deploy/rollback, logs, platform settings and delete.
 * The database page is the child module apps-database (database/Database.php), the custom domain apps-domain (domain/Domain.php).
 * Every member of the app's project may do all of it except delete, which is the owner's.
 *
 * @property-read Apps_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Templates $templates
 * @property-read Validation $validation
 * @property-read Services $services
 * @property-read Secrets $secrets
 */
class Apps extends Trongate {
    use Customer_guard;
    use Provider_clients;
    use App_form;
    use App_page;

    /** The app submit_delete() checks the typed confirmation against */
    private ?object $app_to_delete = null;

    /** settings() is redisplaying the environment variable form, not the settings form */
    private bool $env_form_posted = false;

    /**
     * projects/{id}/apps/new: the new-app questions. Without a GitHub
     * sign-in the customer can paste a public link or connect GitHub.
     */
    function create(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $customer = $this->_customer_or_redirect();

        $data = [
            'view_module' => 'apps',
            'view_file' => 'create',
            'project' => $project,
            'form' => $this->_app_form_data((int) $customer->id, 'auth/github?then=projects/' . (int) $project->id . '/apps/new'),
            'crumbs' => [['projects/' . $project->id, (string) $project->name], ['projects/' . $project->id . '/apps/new', 'New app']],
            'additional_includes_top' => ['apps_module/css/wizard.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function submit(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $customer = $this->_customer_or_redirect();

        $this->_set_app_rules();

        if ($this->validation->run() !== true) {
            $this->create();
            return;
        }

        $app_id = $this->_create_app_from_post((int) $project->id, (int) $customer->id);

        if ($app_id === false) {
            set_flashdata('The app could not be created, please try again.');
            redirect('projects/' . $project->id . '/apps/new');
        }

        $deployment_id = $this->_queue_first_deploy((int) $customer->id, $app_id);

        redirect($deployment_id === false ? 'apps/' . $app_id : 'deployments/' . $deployment_id);
    }

    function show(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $project_id = (int) $app->project_id;

        $deployments = $this->model->app_deployments($project_id, (int) $app->id);
        $current = empty($app->current_deployment_id)
            ? false
            : $this->model->find_project_deployment($project_id, (int) $app->current_deployment_id);

        $data = [
            'view_module' => 'apps',
            'view_file' => 'show',
            'app' => $app,
            'processes' => $this->model->app_processes((int) $app->id),
            'process_states' => $this->_live_process_states($app),
            'urls' => $this->_app_urls($app),
            'current' => $current,
            'deployments' => $deployments,
            'can_rollback' => $this->model->rollback_target($app) !== false,
            ...$this->_environment_data($app),
            'live_state' => $this->_live_app_state($app),
            'log_rates' => $this->_log_rates($app),
            'database_name' => Manifests::database_name((int) $app->id),
            'setup_sql_failure' => $this->_setup_sql_failure($app),
            'services' => $this->model->project_services($project_id),
            'crumbs' => $this->_app_crumbs($app),
            'additional_includes_top' => [
                'deployments_module/css/deployments.css',
                'apps_module/css/apps.css',
            ],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * Why the app's setup SQL stopped and the SQL that did not run, or
     * null when it ran (or there was none)
     *
     * @return array{message: string, remaining_sql: string}|null
     */
    function _setup_sql_failure(object $app): ?array {
        if (($app->setup_sql_status ?? null) !== 'failed') {
            return null;
        }

        $remaining_sql = Setup_sql::decrypt((string) $app->setup_sql);

        if ($remaining_sql === null) {
            $remaining_sql = '-- It could not be decrypted (APP_KEY changed).';
        }

        return [
            'message' => (string) $app->setup_sql_error,
            'remaining_sql' => $remaining_sql,
        ];
    }

    /**
     * MX partial polled by the app page: the app's live state in the cluster.
     */
    function live_state(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $app = $this->model->find_member_app((int) $customer->id, segment(3, 'int'));

        if ($app === false) {
            http_response_code(404);
            return;
        }

        $this->view('_live_state', ['live_state' => $this->_live_app_state($app)]);
    }

    /**
     * MX partial polled by the app page: the app's processes and what each is doing.
     */
    function processes_partial(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $app = $this->model->find_member_app((int) $customer->id, segment(3, 'int'));

        if ($app === false) {
            http_response_code(404);
            return;
        }

        $this->view('_processes', [
            'app' => $app,
            'processes' => $this->model->app_processes((int) $app->id),
            'process_states' => $this->_live_process_states($app),
        ]);
    }

    /**
     * apps/{id}/logs: the app's recent output, kept up to date by logs_partial().
     */
    function logs(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $logs = $this->_live_app_logs($app);

        $data = [
            'view_module' => 'apps',
            'view_file' => 'logs',
            'app' => $app,
            'urls' => $this->_app_urls($app),
            'can_rollback' => $this->model->rollback_target($app) !== false,
            ...$this->_environment_data($app),
            'live_state' => $logs['state'] ?? null,
            'logs' => $logs,
            'crumbs' => $this->_app_crumbs($app),
            'additional_includes_top' => ['apps_module/css/apps.css'],
            'additional_includes_btm' => ['apps_module/js/app_logs.js'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * MX partial polled by the logs page: lines newer than ?since= (a log
     * timestamp, see App_logs), the next cursor and what the app is doing.
     */
    function logs_partial(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $app = $this->model->find_member_app((int) $customer->id, segment(3, 'int'));

        if ($app === false) {
            http_response_code(404);
            return;
        }

        $cursor = (string) ($_GET['since'] ?? '');

        $this->view('_logs_partial', [
            'app' => $app,
            'logs' => $this->_live_app_logs($app, $cursor),
            'cursor' => $cursor,
        ]);
    }

    function deploy(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $this->_require_post_with_csrf();

        $customer = $this->_customer_or_redirect();

        try {
            $deployment_id = $this->model->queue_deploy(
                (int) $app->project_id,
                (int) $app->id,
                (int) $customer->id,
                'manual'
            );
        } catch (RuntimeException $e) {
            set_flashdata('Could not queue a deployment: ' . htmlspecialchars($e->getMessage()));
            redirect('apps/' . $app->id);
        }

        redirect('deployments/' . $deployment_id);
    }

    /**
     * POST apps/{id}/setup-sql/dismiss: hide the alert about a failed setup SQL
     */
    function dismiss_setup_sql(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $this->model->dismiss_setup_sql((int) $app->id);

        redirect('apps/' . $app->id);
    }

    /**
     * Roll back to a given deployment (POST deployment_id) or, without one, the previous successful one.
     */
    function rollback(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $this->_require_post_with_csrf();

        $target_id = (int) post('deployment_id', true);
        $customer = $this->_customer_or_redirect();

        try {
            $deployment_id = $this->model->queue_rollback(
                (int) $app->project_id,
                (int) $app->id,
                (int) $customer->id,
                $target_id > 0 ? $target_id : null
            );
        } catch (RuntimeException $e) {
            set_flashdata('Could not roll back: ' . htmlspecialchars($e->getMessage()));
            redirect('apps/' . $app->id);
        }

        redirect('deployments/' . $deployment_id);
    }

    function settings(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $is_resubmit = REQUEST_TYPE === 'POST' && !$this->env_form_posted;
        $paths = Manifests::persistent_paths((array) $app);

        $data = [
            'view_module' => 'apps',
            'view_file' => 'settings',
            'app' => $app,
            'urls' => $this->_app_urls($app),
            'persistent_paths' => $is_resubmit ? (string) post('persistent_paths') : implode("\n", $paths),
            'php_extensions' => $is_resubmit ? (string) post('php_extensions', true) : (string) $app->php_extensions,
            'build_commands' => $is_resubmit ? (string) post('build_commands') : (string) $app->build_commands,
            'processes' => $is_resubmit ? $this->_processes_from_post() : $this->model->app_processes((int) $app->id),
            'always_on' => $is_resubmit ? (bool) post('always_on') : (bool) $app->always_on,
            'trongate_env' => $is_resubmit ? (string) post('trongate_env', true) : Trongate_config::env((array) $app),
            'auto_deploy' => $is_resubmit ? (bool) post('auto_deploy') : (bool) $app->auto_deploy,
            'source_branch' => $is_resubmit ? (string) post('source_branch', true) : (string) $app->source_branch,
            'mail_capture' => $is_resubmit ? (bool) post('mail_capture') : Mail_capture::enabled((array) $app),
            'health_path' => $is_resubmit ? (string) post('health_path', true) : Manifests::health_path((array) $app),
            'env_names' => array_keys($this->_app_env($app)),
            'env_name' => $this->env_form_posted ? (string) post('env_name', true) : '',
            'can_delete' => $this->_is_owner($app),
            'crumbs' => $this->_app_crumbs($app),
            'additional_includes_top' => ['apps_module/css/apps.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * POST apps/{id}/env/submit: add or replace one environment variable.
     * Values are write-only: they are never shown again.
     */
    function submit_env(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->editing_app_id = (int) $app->id;

        $this->validation->set_rules('env_name', 'Name', 'required|callback__validate_env_name');
        $this->validation->set_rules('env_value', 'Value', 'required|max_length[' . App_env::MAX_VALUE_BYTES . ']');

        if ($this->validation->run() !== true) {
            $this->env_form_posted = true;
            $this->settings();
            return;
        }

        $name = trim((string) post('env_name'));
        $this->secrets->update(App_env::MODULE, (int) $app->id, App_env::SERVICE, [$name => (string) post('env_value')]);

        set_flashdata(htmlspecialchars($name) . ' saved. It takes effect on the next deployment.');
        redirect('apps/' . $app->id . '/settings');
    }

    /**
     * POST apps/{id}/env/delete: remove one environment variable (POST env_name).
     */
    function delete_env(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $name = (string) post('env_name', true);
        $env = $this->_app_env($app);

        if (array_key_exists($name, $env)) {
            unset($env[$name]);

            $env === []
                ? $this->secrets->delete(App_env::MODULE, (int) $app->id, App_env::SERVICE)
                : $this->secrets->save(App_env::MODULE, (int) $app->id, App_env::SERVICE, $env);

            set_flashdata(htmlspecialchars($name) . ' removed. It is gone from the app after the next deployment.');
        }

        redirect('apps/' . $app->id . '/settings');
    }

    function submit_settings(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->editing_app_id = (int) $app->id;

        $this->validation->set_rules('persistent_paths', 'Persistent Paths', 'max_length[4000]|callback__validate_persistent_paths');
        $this->validation->set_rules('php_extensions', 'PHP Extensions', 'max_length[255]|callback__validate_php_extensions');
        $this->validation->set_rules('build_commands', 'Build Commands', 'callback__validate_build_commands');
        $this->validation->set_rules('processes', 'Processes', 'callback__validate_processes');
        $this->validation->set_rules('trongate_env', 'Environment', 'required|callback__validate_trongate_env');
        $this->validation->set_rules('source_branch', 'Branch', 'required|max_length[100]|callback__validate_branch');
        $this->validation->set_rules('health_path', 'Health Check Path', 'max_length[200]|callback__validate_health_path');

        if ($this->validation->run() !== true) {
            $this->settings();
            return;
        }

        $this->model->update_app_settings((int) $app->project_id, (int) $app->id, [
            // The custom domain has its own tab (apps-domain)
            'domain' => !empty($app->domain) ? (string) $app->domain : null,
            'persistent_paths' => $this->_parse_persistent_paths((string) post('persistent_paths')),
            'php_extensions' => implode(',', $this->_parse_php_extensions((string) post('php_extensions', true))),
            'build_commands' => (string) post('build_commands'),
            'always_on' => (bool) post('always_on'),
            'auto_deploy' => (bool) post('auto_deploy'),
            'source_branch' => trim((string) post('source_branch', true)),
            'trongate_env' => trim((string) post('trongate_env', true)),
            'mail_capture' => (bool) post('mail_capture'),
            'health_path' => $this->_parse_health_path((string) post('health_path', true)),
        ]);

        $this->model->save_app_processes((int) $app->id, $this->_processes_from_post());

        set_flashdata('Settings saved. They take effect on the next deployment.');
        redirect('apps/' . $app->id);
    }

    /**
     * Confirmation page for deleting an app (project owner only).
     */
    function delete(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_make_sure_owner($app, 'Only the project owner can delete an app.', 'apps/' . $app->id . '/settings');

        $data = [
            'view_module' => 'apps',
            'view_file' => 'delete',
            'app' => $app,
            'urls' => $this->_app_urls($app),
            'staging_urls' => $this->_staging_urls($app),
            'name' => $app->environment === 'staging' ? $app->label . ' staging' : $app->label,
            'crumbs' => $this->_app_crumbs($app),
            'additional_includes_top' => ['apps_module/css/apps.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * POST apps/{id}/delete/submit: the customer typed the app's slug to confirm.
     */
    function submit_delete(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_make_sure_owner($app, 'Only the project owner can delete an app.', 'apps/' . $app->id . '/settings');

        if (REQUEST_TYPE !== 'POST') {
            http_response_code(405);
            die('Method not allowed');
        }

        $this->app_to_delete = $app;
        $this->validation->set_rules('confirm_slug', 'App name', 'required|callback__validate_delete_confirmation');

        if ($this->validation->run() !== true) {
            $this->delete();
            return;
        }

        try {
            $this->model->queue_app_delete((int) $app->project_id, (int) $app->id);
        } catch (RuntimeException $e) {
            set_flashdata('Could not delete the app: ' . htmlspecialchars($e->getMessage()));
            redirect('apps/' . $app->id);
        }

        if ($app->environment === 'staging') {
            set_flashdata('Staging was deleted. It is being taken offline now.');
            redirect('apps/' . $app->production_app_id);
        }

        set_flashdata(htmlspecialchars($app->label) . ' was deleted. It is being taken offline now.');
        redirect('projects/' . $app->project_id);
    }

    // --- Helpers ---

    /**
     * The addresses of a production app's staging app, deleted along with it;
     * empty when it has none
     *
     * @return list<string>
     */
    function _staging_urls(object $app): array {
        $staging = $this->model->find_staging_app((int) $app->id);

        if ($staging === false) {
            return [];
        }

        return ['https://' . $staging->slug . '.' . PLATFORM_APPS_DOMAIN];
    }

    /**
     * Hourly error and warning counts of the last 24 hours (App_log_rates),
     * or null before the app's first deployment or when they cannot be read
     *
     * @return list<array{hour: string, total: int, warnings: int, errors: int}>|null
     */
    function _log_rates(object $app): ?array {
        if (empty($app->current_deployment_id)) {
            return null;
        }

        try {
            return App_log_rates::hourly($this->model->log_rate_rows((int) $app->id), time());
        } catch (Throwable $e) {
            return null;
        }
    }

    // --- Validation callbacks (the settings ones are in App_rules) ---

    function _validate_delete_confirmation(string $value): bool|string {
        if ($this->app_to_delete === null || trim($value) !== (string) $this->app_to_delete->slug) {
            return 'Type the app name exactly as shown to confirm.';
        }

        return true;
    }
}
