<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/services/traits/Provider_clients.php';
require_once __DIR__ . '/traits/Project_form.php';
require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once APPPATH . 'modules/platform/traits/Live_app_state.php';

/**
 * Customer projects: create, overview, deploy/rollback and platform settings.
 *
 * @property-read Projects_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Templates $templates
 * @property-read Validation $validation
 * @property-read Services $services
 * @property-read Secrets $secrets
 */
class Projects extends Trongate {
    use Customer_guard;
    use Provider_clients;
    use Project_form;
    use Live_app_state;

    const MAX_PERSISTENT_PATHS = 20;

    /** Top-level directories the platform mounts or owns; never persistent */
    const RESERVED_PATHS = ['config', 'engine'];

    function create(): void {
        $this->_make_sure_allowed();
        $team_id = $this->_team_id_or_redirect();

        $data = [
            'view_module' => 'projects',
            'view_file' => 'create',
            'form' => $this->_project_form_data($team_id),
            'additional_includes_top' => ['projects_module/css/projects.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function submit(): void {
        $this->_make_sure_allowed();
        $team_id = $this->_team_id_or_redirect();

        $this->_set_project_rules();

        if ($this->validation->run() !== true) {
            $this->create();
            return;
        }

        $project_id = $this->_create_project_from_post($team_id);

        if ($project_id === false) {
            set_flashdata('The project could not be created, please try again.');
            redirect('projects/new');
        }

        $deployment_id = $this->_queue_first_deploy($team_id, $project_id);

        redirect($deployment_id === false ? 'projects/' . $project_id : 'deployments/' . $deployment_id);
    }

    /**
     * Connect GitHub for a team that has none yet (teams created after onboarding).
     */
    function submit_source(): void {
        $this->_make_sure_allowed();
        $team_id = $this->_team_id_or_redirect();

        $this->validation->set_rules('provider', 'Source Control Provider', 'required|callback__validate_source_provider');
        $this->validation->set_rules('token', 'Personal Access Token', 'required|min_length[10]|callback__validate_github_token');

        if ($this->validation->run() !== true) {
            $this->create();
            return;
        }

        $this->secrets->save('team', $team_id, post('provider', true), ['token' => post('token', true)]);

        redirect('projects/new');
    }

    function show(): void {
        $this->_make_sure_allowed();
        $team_id = $this->_team_id_or_redirect();
        $project = $this->_find_or_redirect($team_id, segment(3, 'int'));

        $deployments = $this->model->project_deployments($team_id, (int) $project->id);
        $current = empty($project->current_deployment_id)
            ? false
            : $this->model->find_team_deployment($team_id, (int) $project->current_deployment_id);

        $data = [
            'view_module' => 'projects',
            'view_file' => 'show',
            'project' => $project,
            'urls' => $this->_project_urls($project),
            'current' => $current,
            'deployments' => $deployments,
            'can_rollback' => $this->model->rollback_target($project) !== false,
            'live_state' => $this->_live_app_state($project),
            'additional_includes_top' => [
                'deployments_module/css/deployments.css',
                'projects_module/css/projects.css',
            ],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * MX partial polled by the project page: the app's live state in the cluster.
     */
    function live_state(): void {
        $this->_make_sure_allowed();
        $team_id = $this->_team_id_or_redirect();
        $project = $this->model->find_team_project($team_id, segment(3, 'int'));

        if ($project === false) {
            http_response_code(404);
            return;
        }

        $this->view('_live_state', ['live_state' => $this->_live_app_state($project)]);
    }

    function deploy(): void {
        $this->_make_sure_allowed();
        $team_id = $this->_team_id_or_redirect();
        $project = $this->_find_or_redirect($team_id, segment(3, 'int'));

        $this->_require_post_with_csrf();

        $customer = $this->model->_get_current_customer();
        $member = $this->model->get_current_team_member();

        try {
            $deployment_id = $this->model->queue_deploy(
                $team_id,
                (int) $project->id,
                $member === false ? null : (int) $member->id,
                $customer === false ? null : (int) $customer->id,
                'manual'
            );
        } catch (RuntimeException $e) {
            set_flashdata('Could not queue a deployment: ' . $e->getMessage());
            redirect('projects/' . $project->id);
        }

        redirect('deployments/' . $deployment_id);
    }

    /**
     * Roll back to a given deployment (POST deployment_id) or, without one, the previous successful one.
     */
    function rollback(): void {
        $this->_make_sure_allowed();
        $team_id = $this->_team_id_or_redirect();
        $project = $this->_find_or_redirect($team_id, segment(3, 'int'));

        $this->_require_post_with_csrf();

        $target_id = (int) post('deployment_id', true);
        $customer = $this->model->_get_current_customer();

        try {
            $deployment_id = $this->model->queue_rollback(
                $team_id,
                (int) $project->id,
                $customer === false ? null : (int) $customer->id,
                $target_id > 0 ? $target_id : null
            );
        } catch (RuntimeException $e) {
            set_flashdata('Could not roll back: ' . $e->getMessage());
            redirect('projects/' . $project->id);
        }

        redirect('deployments/' . $deployment_id);
    }

    function settings(): void {
        $this->_make_sure_allowed();
        $team_id = $this->_team_id_or_redirect();
        $project = $this->_find_or_redirect($team_id, segment(3, 'int'));

        $is_resubmit = REQUEST_TYPE === 'POST';
        $paths = Manifests::persistent_paths((array) $project);

        $data = [
            'view_module' => 'projects',
            'view_file' => 'settings',
            'project' => $project,
            'urls' => $this->_project_urls($project),
            'domain' => $is_resubmit ? (string) post('domain', true) : (string) ($project->domain ?? ''),
            'persistent_paths' => $is_resubmit ? (string) post('persistent_paths') : implode("\n", $paths),
            'php_extensions' => $is_resubmit ? (string) post('php_extensions', true) : (string) $project->php_extensions,
            'always_on' => $is_resubmit ? (bool) post('always_on') : (bool) $project->always_on,
            'resource_tier' => $is_resubmit ? (string) post('resource_tier', true) : (string) $project->resource_tier,
            'resource_tiers' => Resource_tiers::options(),
            'domain_record' => !empty($project->domain) ? $this->model->domain_verification_record((int) $project->id, (string) $project->domain) : null,
            'webhook_url' => BASE_URL . 'webhooks/github/' . $project->id,
            'webhook_secret' => $this->model->ensure_webhook_secret($team_id, (int) $project->id),
            'additional_includes_top' => ['projects_module/css/projects.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function submit_settings(): void {
        $this->_make_sure_allowed();
        $team_id = $this->_team_id_or_redirect();
        $project = $this->_find_or_redirect($team_id, segment(3, 'int'));

        $this->validation->set_rules('domain', 'Custom Domain', 'max_length[253]|callback__validate_domain');
        $this->validation->set_rules('persistent_paths', 'Persistent Paths', 'max_length[4000]|callback__validate_persistent_paths');
        $this->validation->set_rules('php_extensions', 'PHP Extensions', 'max_length[255]|callback__validate_php_extensions');
        $this->validation->set_rules('resource_tier', 'Resources', 'required|callback__validate_resource_tier');

        if ($this->validation->run() !== true) {
            $this->settings();
            return;
        }

        $this->model->update_project_settings($team_id, (int) $project->id, [
            'domain' => $this->_normalise_domain((string) post('domain', true)),
            'persistent_paths' => $this->_parse_persistent_paths((string) post('persistent_paths')),
            'php_extensions' => implode(',', $this->_parse_php_extensions((string) post('php_extensions', true))),
            'always_on' => (bool) post('always_on'),
            'resource_tier' => (string) post('resource_tier', true),
        ]);

        set_flashdata('Settings saved. They take effect on the next deployment.');
        redirect('projects/' . $project->id);
    }

    /**
     * POST projects/{id}/verify-domain: check the domain's TXT record.
     */
    function verify_domain(): void {
        $this->_make_sure_allowed();
        $team_id = $this->_team_id_or_redirect();
        $project = $this->_find_or_redirect($team_id, segment(3, 'int'));
        $this->_require_post_with_csrf();

        $result = $this->model->verify_project_domain($team_id, (int) $project->id);

        set_flashdata($result === true
            ? 'Domain verified. Deploy to start serving it.'
            : 'Could not verify the domain: ' . $result);
        redirect('projects/' . $project->id . '/settings');
    }

    // --- Helpers ---

    function _find_or_redirect(int $team_id, int $project_id): object {
        $project = $this->model->find_team_project($team_id, $project_id);

        if ($project === false) {
            redirect('dashboard');
        }

        return $project;
    }

    /**
     * Public URLs of a project: the platform subdomain first, then the custom domain.
     *
     * @return list<string>
     */
    function _project_urls(object $project): array {
        return array_map(fn(string $host) => 'https://' . $host, Manifests::hostnames((array) $project));
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

    function _validate_php_extensions(string $value): bool|string {
        foreach ($this->_parse_php_extensions($value) as $name) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) {
                return "\"{$name}\" is not a valid PHP extension name.";
            }
        }

        return true;
    }

    function _validate_resource_tier(string $tier): bool|string {
        if (!Resource_tiers::exists($tier)) {
            return 'Please choose one of the listed resource sizes.';
        }

        return true;
    }
}
