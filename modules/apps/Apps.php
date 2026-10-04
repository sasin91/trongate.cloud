<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/services/traits/Provider_clients.php';
require_once __DIR__ . '/traits/App_form.php';
require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once APPPATH . 'modules/platform/manifests/Domain_dns.php';
require_once APPPATH . 'modules/platform/traits/Live_app_state.php';

/**
 * Customer apps: create, overview, deploy/rollback, platform settings and delete.
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

    /** Project roles that may delete a app (db/003_projects.sql); deploys are open to every member */
    const DELETE_ROLES = ['owner', 'admin'];

    /** The app submit_delete() checks the typed confirmation against */
    private ?object $app_to_delete = null;

    function create(): void {
        $this->_make_sure_allowed();
        $project_id = $this->_project_id_or_redirect();

        $data = [
            'view_module' => 'apps',
            'view_file' => 'create',
            'form' => $this->_app_form_data($project_id),
            'additional_includes_top' => ['apps_module/css/wizard.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function submit(): void {
        $this->_make_sure_allowed();
        $project_id = $this->_project_id_or_redirect();

        $this->_set_app_rules();

        if ($this->validation->run() !== true) {
            $this->create();
            return;
        }

        $app_id = $this->_create_app_from_post($project_id);

        if ($app_id === false) {
            set_flashdata('The app could not be created, please try again.');
            redirect('apps/new');
        }

        $deployment_id = $this->_queue_first_deploy($project_id, $app_id);

        redirect($deployment_id === false ? 'apps/' . $app_id : 'deployments/' . $deployment_id);
    }

    /**
     * Connect GitHub for a project that has none yet (projects created after onboarding).
     */
    function submit_source(): void {
        $this->_make_sure_allowed();
        $project_id = $this->_project_id_or_redirect();

        $this->validation->set_rules('provider', 'Source Control Provider', 'required|callback__validate_source_provider');
        $this->validation->set_rules('token', 'Personal Access Token', 'required|min_length[10]|callback__validate_github_token');

        if ($this->validation->run() !== true) {
            $this->create();
            return;
        }

        $this->secrets->save('project', $project_id, post('provider', true), ['token' => post('token', true)]);

        redirect('apps/new');
    }

    function show(): void {
        $this->_make_sure_allowed();
        $project_id = $this->_project_id_or_redirect();
        $app = $this->_find_or_redirect($project_id, segment(3, 'int'));

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
        $project_id = $this->_project_id_or_redirect();
        $app = $this->model->find_project_app($project_id, segment(3, 'int'));

        if ($app === false) {
            http_response_code(404);
            return;
        }

        $this->view('_live_state', ['live_state' => $this->_live_app_state($app)]);
    }

    function deploy(): void {
        $this->_make_sure_allowed();
        $project_id = $this->_project_id_or_redirect();
        $app = $this->_find_or_redirect($project_id, segment(3, 'int'));

        $this->_require_post_with_csrf();

        $customer = $this->model->_get_current_customer();
        $member = $this->model->get_current_project_member();

        try {
            $deployment_id = $this->model->queue_deploy(
                $project_id,
                (int) $app->id,
                $member === false ? null : (int) $member->id,
                $customer === false ? null : (int) $customer->id,
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
        $project_id = $this->_project_id_or_redirect();
        $app = $this->_find_or_redirect($project_id, segment(3, 'int'));

        $this->_require_post_with_csrf();

        $target_id = (int) post('deployment_id', true);
        $customer = $this->model->_get_current_customer();

        try {
            $deployment_id = $this->model->queue_rollback(
                $project_id,
                (int) $app->id,
                $customer === false ? null : (int) $customer->id,
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
        $project_id = $this->_project_id_or_redirect();
        $app = $this->_find_or_redirect($project_id, segment(3, 'int'));

        $is_resubmit = REQUEST_TYPE === 'POST';
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
            'resource_tier' => $is_resubmit ? (string) post('resource_tier', true) : (string) $app->resource_tier,
            'resource_tiers' => Resource_tiers::options(),
            'auto_deploy' => $is_resubmit ? (bool) post('auto_deploy') : (bool) $app->auto_deploy,
            'domain_record' => !empty($app->domain) ? $this->model->domain_verification_record((int) $app->id, (string) $app->domain) : null,
            'domain_routed' => Manifests::custom_domains((array) $app) !== [],
            'domain_secured' => Manifests::secured_domains((array) $app) !== [],
            'ingress_addresses' => Domain_dns::ingress_addresses(),
            'webhook_url' => BASE_URL . 'webhooks/github/' . $app->id,
            'webhook_secret' => $this->model->ensure_webhook_secret($project_id, (int) $app->id),
            'can_delete' => $this->_may_delete_apps(),
            'additional_includes_top' => ['apps_module/css/apps.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function submit_settings(): void {
        $this->_make_sure_allowed();
        $project_id = $this->_project_id_or_redirect();
        $app = $this->_find_or_redirect($project_id, segment(3, 'int'));

        $this->validation->set_rules('domain', 'Custom Domain', 'max_length[253]|callback__validate_domain');
        $this->validation->set_rules('persistent_paths', 'Persistent Paths', 'max_length[4000]|callback__validate_persistent_paths');
        $this->validation->set_rules('php_extensions', 'PHP Extensions', 'max_length[255]|callback__validate_php_extensions');
        $this->validation->set_rules('resource_tier', 'Resources', 'required|callback__validate_resource_tier');

        if ($this->validation->run() !== true) {
            $this->settings();
            return;
        }

        $this->model->update_app_settings($project_id, (int) $app->id, [
            'domain' => $this->_normalise_domain((string) post('domain', true)),
            'persistent_paths' => $this->_parse_persistent_paths((string) post('persistent_paths')),
            'php_extensions' => implode(',', $this->_parse_php_extensions((string) post('php_extensions', true))),
            'always_on' => (bool) post('always_on'),
            'resource_tier' => (string) post('resource_tier', true),
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
        $project_id = $this->_project_id_or_redirect();
        $app = $this->_find_or_redirect($project_id, segment(3, 'int'));
        $this->_require_post_with_csrf();

        $result = $this->model->verify_app_domain($project_id, (int) $app->id);

        set_flashdata($result === true
            ? 'Domain verified. It goes live by itself once its DNS points here; checking now.'
            : 'Could not verify the domain: ' . $result);
        redirect('apps/' . $app->id . '/settings');
    }

    /**
     * Confirmation page for deleting a app.
     */
    function delete(): void {
        $this->_make_sure_allowed();
        $project_id = $this->_project_id_or_redirect();
        $app = $this->_find_or_redirect($project_id, segment(3, 'int'));
        $this->_make_sure_may_delete($app);

        $data = [
            'view_module' => 'apps',
            'view_file' => 'delete',
            'app' => $app,
            'urls' => $this->_app_urls($app),
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
        $project_id = $this->_project_id_or_redirect();
        $app = $this->_find_or_redirect($project_id, segment(3, 'int'));
        $this->_make_sure_may_delete($app);

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
            $this->model->queue_app_delete($project_id, (int) $app->id);
        } catch (RuntimeException $e) {
            set_flashdata('Could not delete the app: ' . $e->getMessage());
            redirect('apps/' . $app->id);
        }

        set_flashdata(htmlspecialchars($app->label) . ' was deleted. Its app is being removed now.');
        redirect('dashboard');
    }

    // --- Helpers ---

    function _find_or_redirect(int $project_id, int $app_id): object {
        $app = $this->model->find_project_app($project_id, $app_id);

        if ($app === false) {
            redirect('dashboard');
        }

        return $app;
    }

    /**
     * Whether the current customer's role in the current project may delete
     * apps. Deleting is the one irreversible app action, so unlike
     * deploys and rollbacks it is not open to every member.
     */
    function _may_delete_apps(): bool {
        $member = $this->model->get_current_project_member();

        return $member !== false && in_array((string) $member->role, self::DELETE_ROLES, true);
    }

    function _make_sure_may_delete(object $app): void {
        if (!$this->_may_delete_apps()) {
            set_flashdata('Only project owners and admins can delete a app.');
            redirect('apps/' . $app->id . '/settings');
        }
    }

    /**
     * Public URLs of a app: the platform subdomain first, then the custom domain.
     *
     * @return list<string>
     */
    function _app_urls(object $app): array {
        return array_map(fn(string $host) => 'https://' . $host, Manifests::hostnames((array) $app));
    }

    /**
     * Only accept POSTs carrying a valid CSRF token (Validation::run() blocks the request otherwise).
     */
    function _require_post_with_csrf(): void {
        if (REQUEST_TYPE !== 'POST') {
            http_response_code(405);
            die('Method not allowed');
        }

        $this->validation->run();
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

    /**
     * The tier must be on the ladder, and the project's apps must still fit its
     * quota with it (Manifests::quota_shortfall); otherwise every later rollout
     * in the project would hang on "exceeded quota". Moving down is always allowed.
     */
    function _validate_resource_tier(string $tier): bool|string {
        if (!Resource_tiers::exists($tier)) {
            return 'Please choose one of the listed resource sizes.';
        }

        $project_id = $this->_project_id_or_redirect();
        $app = $this->_find_or_redirect($project_id, segment(3, 'int'));

        if (Resource_tiers::rank($tier) <= Resource_tiers::rank((string) $app->resource_tier)) {
            return true;
        }

        $tiers = $this->model->project_resource_tiers($project_id, (int) $app->id);
        $tiers[] = $tier;

        if (Manifests::quota_shortfall($tiers) !== null) {
            return "Your project's apps would not fit in its resources with this size. Choose a smaller size, or make another app smaller first.";
        }

        return true;
    }
}
