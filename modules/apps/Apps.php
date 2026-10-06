<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/services/traits/Provider_clients.php';
require_once __DIR__ . '/traits/App_form.php';
require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once APPPATH . 'modules/platform/manifests/App_env.php';
require_once APPPATH . 'modules/platform/manifests/Domain_dns.php';
require_once APPPATH . 'modules/platform/traits/Live_app_state.php';

/**
 * Apps inside a project: create, overview, deploy/rollback, platform settings and delete.
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
    use Live_app_state;

    const MAX_PERSISTENT_PATHS = 20;

    /** Top-level directories the platform mounts or owns; never persistent */
    const RESERVED_PATHS = ['config', 'engine'];

    /** The app submit_delete() checks the typed confirmation against */
    private ?object $app_to_delete = null;

    /** settings() is redisplaying the environment variable form, not the settings form */
    private bool $env_form_posted = false;

    /**
     * projects/{id}/apps/new: the new-app questions, or Connect GitHub first
     * when the customer has not connected it yet.
     */
    function create(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $customer = $this->_customer_or_redirect();

        $data = [
            'view_module' => 'apps',
            'view_file' => 'create',
            'project' => $project,
            'form' => $this->_app_form_data((int) $customer->id),
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
            'urls' => $this->_app_urls($app),
            'current' => $current,
            'deployments' => $deployments,
            'can_rollback' => $this->model->rollback_target($app) !== false,
            'live_state' => $this->_live_app_state($app),
            'database_name' => Manifests::database_name((int) $app->id),
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
            set_flashdata('Could not queue a deployment: ' . $e->getMessage());
            redirect('apps/' . $app->id);
        }

        redirect('deployments/' . $deployment_id);
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
            set_flashdata('Could not roll back: ' . $e->getMessage());
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
            'domain' => $is_resubmit ? (string) post('domain', true) : (string) ($app->domain ?? ''),
            'persistent_paths' => $is_resubmit ? (string) post('persistent_paths') : implode("\n", $paths),
            'php_extensions' => $is_resubmit ? (string) post('php_extensions', true) : (string) $app->php_extensions,
            'always_on' => $is_resubmit ? (bool) post('always_on') : (bool) $app->always_on,
            'auto_deploy' => $is_resubmit ? (bool) post('auto_deploy') : (bool) $app->auto_deploy,
            'domain_record' => !empty($app->domain) ? $this->model->domain_verification_record((int) $app->id, (string) $app->domain) : null,
            'domain_routed' => Manifests::custom_domains((array) $app) !== [],
            'domain_secured' => Manifests::secured_domains((array) $app) !== [],
            'ingress_addresses' => Domain_dns::ingress_addresses(),
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

        $this->validation->set_rules('domain', 'Custom Domain', 'max_length[253]|callback__validate_domain');
        $this->validation->set_rules('persistent_paths', 'Persistent Paths', 'max_length[4000]|callback__validate_persistent_paths');
        $this->validation->set_rules('php_extensions', 'PHP Extensions', 'max_length[255]|callback__validate_php_extensions');

        if ($this->validation->run() !== true) {
            $this->settings();
            return;
        }

        $this->model->update_app_settings((int) $app->project_id, (int) $app->id, [
            'domain' => $this->_normalise_domain((string) post('domain', true)),
            'persistent_paths' => $this->_parse_persistent_paths((string) post('persistent_paths')),
            'php_extensions' => implode(',', $this->_parse_php_extensions((string) post('php_extensions', true))),
            'always_on' => (bool) post('always_on'),
            'auto_deploy' => (bool) post('auto_deploy'),
        ]);

        set_flashdata('Settings saved. They take effect on the next deployment.');
        redirect('apps/' . $app->id);
    }

    /**
     * POST apps/{id}/verify-domain: check the domain's TXT record, then (in
     * the worker) whether its DNS points here yet.
     */
    function verify_domain(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $result = $this->model->verify_app_domain((int) $app->project_id, (int) $app->id);

        set_flashdata($result === true
            ? 'Domain verified. It goes live by itself once its DNS points here; checking now.'
            : 'Could not verify the domain: ' . $result);
        redirect('apps/' . $app->id . '/settings');
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
            set_flashdata('Could not delete the app: ' . $e->getMessage());
            redirect('apps/' . $app->id);
        }

        set_flashdata(htmlspecialchars($app->label) . ' was deleted. It is being taken offline now.');
        redirect('projects/' . $app->project_id);
    }

    // --- Helpers ---

    /**
     * Header breadcrumb: the app's project, then the app
     *
     * @return list<array{0: string, 1: string}>
     */
    function _app_crumbs(object $app): array {
        return [
            ['projects/' . $app->project_id, (string) $app->project_name],
            ['apps/' . $app->id, (string) $app->label],
        ];
    }

    /**
     * The app's environment variables, decrypted (App_env)
     *
     * @return array<string, string>
     */
    function _app_env(object $app): array {
        $this->module('secrets');

        return $this->secrets->exists(App_env::MODULE, (int) $app->id, App_env::SERVICE)
            ? $this->secrets->get(App_env::MODULE, (int) $app->id, App_env::SERVICE)
            : [];
    }

    /**
     * Public URLs of an app: the platform subdomain first, then the custom domain.
     *
     * @return list<string>
     */
    function _app_urls(object $app): array {
        return array_map(fn(string $host) => 'https://' . $host, Manifests::hostnames((array) $app));
    }

    /**
     * @return list<string> Normalised paths (no leading "./", no trailing "/", unique)
     */
    function _parse_persistent_paths(string $value): array {
        $paths = [];

        foreach (preg_split('/\s+/', trim($value)) ?: [] as $path) {
            $path = rtrim(preg_replace('#^(\./)+#', '', $path) ?? '', '/');

            if ($path !== '') {
                $paths[$path] = $path;
            }
        }

        return array_values($paths);
    }

    /**
     * @return list<string> Lowercase extension names
     */
    function _parse_php_extensions(string $value): array {
        $names = preg_split('/[\s,]+/', strtolower(trim($value))) ?: [];

        return array_values(array_unique(array_filter($names, fn(string $name) => $name !== '')));
    }

    // --- Validation callbacks ---

    /**
     * One relative path per line (the validator sees newlines collapsed to spaces).
     */
    function _validate_persistent_paths(string $value): bool|string {
        $paths = $this->_parse_persistent_paths($value);

        if (count($paths) > self::MAX_PERSISTENT_PATHS) {
            return 'At most ' . self::MAX_PERSISTENT_PATHS . ' persistent paths are allowed.';
        }

        foreach ($paths as $path) {
            if (!preg_match('#^[A-Za-z0-9._/-]+$#', $path) || strlen($path) > 200) {
                return "\"{$path}\" may only contain letters, numbers, dots, dashes, underscores and slashes.";
            }

            if (str_starts_with($path, '/')) {
                return "\"{$path}\" must be relative to your app's root, e.g. modules/blog/uploads.";
            }

            $segments = explode('/', $path);
            if (in_array('..', $segments, true) || in_array('.', $segments, true) || in_array('', $segments, true)) {
                return "\"{$path}\" may not contain \"..\", \".\" or empty segments.";
            }

            if (in_array($segments[0], self::RESERVED_PATHS, true)) {
                return "\"{$path}\" is managed by the platform and cannot be persistent.";
            }
        }

        return true;
    }

    function _validate_env_name(string $value): bool|string {
        $name = trim($value);
        $error = App_env::name_error($name);

        if ($error !== null) {
            return $error;
        }

        $env = $this->_app_env((object) ['id' => $this->editing_app_id]);

        if (!array_key_exists($name, $env) && count($env) >= App_env::MAX_VARIABLES) {
            return 'An app can have at most ' . App_env::MAX_VARIABLES . ' environment variables.';
        }

        return true;
    }

    function _validate_delete_confirmation(string $value): bool|string {
        if ($this->app_to_delete === null || trim($value) !== (string) $this->app_to_delete->slug) {
            return 'Type the app name exactly as shown to confirm.';
        }

        return true;
    }

    function _validate_php_extensions(string $value): bool|string {
        foreach ($this->_parse_php_extensions($value) as $name) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) {
                return "\"{$name}\" is not a valid PHP extension name.";
            }
        }

        return true;
    }
}
