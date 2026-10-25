<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/platform/traits/Live_app_state.php';
require_once APPPATH . 'modules/platform/manifests/Project_service.php';
require_once APPPATH . 'modules/platform/live/App_traffic.php';
require_once APPPATH . 'modules/platform/live/App_backup.php';

/**
 * Projects: a group of apps sharing one namespace, and the people who work on them.
 * Every member sees and deploys all of a project's apps and adds or removes its
 * services (Project_service); the owner also renames and deletes the project
 * and manages its members.
 *
 * @property-read Secrets $secrets
 * @property-read Projects_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Templates $templates
 * @property-read Validation $validation
 */
class Projects extends Trongate {
    use Customer_guard;
    use Live_app_state;

    const NAME_RULES = 'required|min_length[3]|max_length[50]';

    function index(): void {
        redirect('dashboard');
    }

    function create(): void {
        $this->_make_sure_allowed();

        $data = [
            'view_module' => 'projects',
            'view_file' => 'create',
            'name' => (string) post('name', true),
            'additional_includes_top' => ['apps_module/css/apps.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function submit(): void {
        $this->_make_sure_allowed();
        $this->_require_post();

        $this->validation->set_rules('name', 'Project name', self::NAME_RULES);

        if ($this->validation->run() !== true) {
            $this->create();
            return;
        }

        $customer = $this->_customer_or_redirect();

        try {
            $project_id = $this->model->create_project((int) $customer->id, post('name', true));
        } catch (InvalidArgumentException $e) {
            set_flashdata(htmlspecialchars($e->getMessage()));
            redirect('projects/new');
        }

        set_flashdata('Project created. Deploy its first app when you are ready.');
        redirect('projects/' . $project_id);
    }

    /**
     * Project page: its apps with their live state, and its members.
     */
    function show(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));

        $apps = $this->model->project_apps((int) $project->id);
        $live_states = empty($apps) ? null : $this->_live_app_states($project);

        foreach ($apps as $app) {
            $app->live_state = $this->_live_state_of($live_states, $app);
        }

        $customer = $this->_customer_or_redirect();

        $data = [
            'view_module' => 'projects',
            'view_file' => 'show',
            'project' => $project,
            'apps' => $apps,
            'members' => $this->model->project_members((int) $project->id),
            'app_backups' => $this->_app_backup_rows((int) $project->id),
            'backup_keep_days' => App_backup::KEEP_DAYS,
            'is_owner' => $this->_is_owner($project),
            'customer_id' => (int) $customer->id,
            'email' => REQUEST_TYPE === 'POST' ? (string) post('email', true) : '',
            'crumbs' => [['projects/' . $project->id, $project->name]],
            'additional_includes_top' => [
                'deployments_module/css/deployments.css',
                'apps_module/css/apps.css',
                'projects_module/css/projects.css',
            ],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * projects/{id}/traffic: requests to every app of the project as they
     * happen (App_traffic), polled from traffic_poll() by app_traffic.js
     */
    function traffic(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $apps = $this->model->project_apps((int) $project->id);

        $data = [
            'view_module' => 'projects',
            'view_file' => 'traffic',
            'project' => $project,
            'app_count' => count($apps),
            'member_count' => count($this->model->project_members((int) $project->id)),
            'is_owner' => $this->_is_owner($project),
            'traffic_apps' => array_column(array_map(fn(object $app) => ['id' => (int) $app->id, 'label' => (string) $app->label], $apps), 'label', 'id'),
            'crumbs' => [['projects/' . $project->id, $project->name], ['projects/' . $project->id . '/traffic', 'Traffic']],
            'additional_includes_top' => [
                'deployments_module/css/deployments.css',
                'apps_module/css/apps.css',
                'projects_module/css/projects.css',
            ],
            'additional_includes_btm' => ['apps_module/js/app_traffic.js'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * projects/{id}/services: the project's services (Project_service) and the Add service form
     */
    function services(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $services = $this->model->project_services((int) $project->id);

        foreach ($services as $service) {
            $this->_add_service_display($service);
        }

        $data = [
            'view_module' => 'projects',
            'view_file' => 'services',
            'project' => $project,
            'services' => $services,
            'can_add_service' => count($services) < Project_service::MAX_PER_PROJECT,
            'service_form' => $this->_service_form(),
            'app_count' => count($this->model->project_apps((int) $project->id)),
            'member_count' => count($this->model->project_members((int) $project->id)),
            'is_owner' => $this->_is_owner($project),
            'crumbs' => [['projects/' . $project->id, $project->name], ['projects/' . $project->id . '/services', 'Services']],
            'additional_includes_top' => [
                'deployments_module/css/deployments.css',
                'apps_module/css/apps.css',
                'projects_module/css/projects.css',
            ],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * What the services list shows for one service: its status pill and the
     * {NAME}_URL line apps get
     */
    function _add_service_display(object $service): void {
        $status = (string) ($service->status ?? 'queued');
        $status_class = 'pending';

        if ($status === 'running') {
            $status_class = 'success';
        }

        if ($status === 'failed') {
            $status_class = 'failed';
        }

        $service->status = $status;
        $service->status_class = $status_class;
        $service->url_line = Project_service::url_variable($service->name) . '=' . Project_service::url((int) $service->id, (int) $service->port);
        $service->remove_question = 'Remove ' . $service->name . '? Apps calling it will get errors until you redeploy them without it.';
    }

    /**
     * JSON polled by the traffic page: the project's requests newer than ?since=
     */
    function traffic_poll(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $project = $this->model->find_member_project((int) $customer->id, segment(3, 'int'));

        if ($project === false) {
            http_response_code(404);
            return;
        }

        $this->_send_traffic(App_traffic::project_pattern($project));
    }

    /**
     * Owner only: rename, and delete once the project holds no apps.
     */
    function settings(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $this->_make_sure_owner($project, 'Only the project owner can change its settings.', 'projects/' . $project->id);

        $data = [
            'view_module' => 'projects',
            'view_file' => 'settings',
            'project' => $project,
            'name' => REQUEST_TYPE === 'POST' ? (string) post('name', true) : (string) $project->name,
            'app_count' => count($this->model->project_apps((int) $project->id)),
            'crumbs' => [['projects/' . $project->id, $project->name]],
            'additional_includes_top' => ['apps_module/css/apps.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    function submit_settings(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $this->_make_sure_owner($project, 'Only the project owner can rename it.', 'projects/' . $project->id);
        $this->_require_post();

        $this->validation->set_rules('name', 'Project name', self::NAME_RULES);

        if ($this->validation->run() !== true) {
            $this->settings();
            return;
        }

        $result = $this->model->rename_project((int) $project->id, post('name', true));

        if ($result !== true) {
            set_flashdata(htmlspecialchars((string) $result));
            redirect('projects/' . $project->id . '/settings');
        }

        set_flashdata('Project renamed.');
        redirect('projects/' . $project->id);
    }

    /**
     * POST projects/{id}/delete: owner only, and only while the project holds no apps.
     */
    function submit_delete(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $this->_make_sure_owner($project, 'Only the project owner can delete it.', 'projects/' . $project->id);
        $this->_require_post_with_csrf();

        if (count($this->model->project_apps((int) $project->id)) > 0) {
            set_flashdata('Delete the apps in this project first.');
            redirect('projects/' . $project->id . '/settings');
        }

        try {
            $this->model->queue_project_delete((int) $project->id);
        } catch (RuntimeException $e) {
            set_flashdata('Could not delete the project: ' . htmlspecialchars($e->getMessage()));
            redirect('projects/' . $project->id . '/settings');
        }

        set_flashdata(htmlspecialchars($project->name) . ' was deleted.');
        redirect('dashboard');
    }

    /**
     * POST projects/{id}/members/add: owner adds an existing account by email.
     */
    function add_member(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $this->_make_sure_owner($project, 'Only the project owner can add members.', 'projects/' . $project->id);
        $this->_require_post();

        $this->validation->set_rules('email', 'email', 'required|valid_email|max_length[255]');

        if ($this->validation->run() !== true) {
            $this->show();
            return;
        }

        $email = strtolower(trim((string) post('email', true)));
        $result = $this->model->add_project_member((int) $project->id, $email);

        set_flashdata($result === true
            ? htmlspecialchars($email) . ' can now see and deploy this project\'s apps.'
            : htmlspecialchars($result));
        redirect('projects/' . $project->id);
    }

    /**
     * POST projects/{id}/members/{customer id}/remove: owner removes a member (never the owner).
     */
    function remove_member(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $this->_make_sure_owner($project, 'Only the project owner can remove members.', 'projects/' . $project->id);
        $this->_require_post_with_csrf();

        $result = $this->model->remove_project_member((int) $project->id, segment(4, 'int'));

        set_flashdata($result === true ? 'Member removed.' : htmlspecialchars($result));
        redirect('projects/' . $project->id);
    }

    /**
     * POST projects/{id}/services/add: add a service (name, image, port,
     * memory, KEY=value lines) and queue its first deploy
     */
    function add_service(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $form = $this->_service_form();
        $back = 'projects/' . $project->id . '/services';

        try {
            $env = $this->_parse_service_env($form['env']);
            $service_id = $this->model->add_service((int) $project->id, $form);
        } catch (InvalidArgumentException $e) {
            set_flashdata(htmlspecialchars($e->getMessage()));
            redirect($back);
        }

        if ($env !== []) {
            $this->module('secrets');
            $this->secrets->save(Project_service::MODULE, $service_id, Project_service::SERVICE, $env);
        }

        $this->model->queue_service_deploy((int) $project->id, $service_id);

        set_flashdata(htmlspecialchars($form['name']) . ' is starting. Deploy your apps to give them '
            . htmlspecialchars(Project_service::url_variable($form['name'])) . '.');
        redirect($back);
    }

    /**
     * POST projects/{id}/services/{service id}/redeploy: apply it again (a
     * crashed service, or a new image under the same tag)
     */
    function redeploy_service(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        try {
            $this->model->queue_service_deploy((int) $project->id, segment(4, 'int'));
            set_flashdata('Redeploy queued.');
        } catch (RuntimeException $e) {
            set_flashdata(htmlspecialchars($e->getMessage()));
        }

        redirect('projects/' . $project->id . '/services');
    }

    /**
     * POST projects/{id}/services/{service id}/delete
     */
    function delete_service(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        try {
            $this->model->queue_service_delete((int) $project->id, segment(4, 'int'));
            set_flashdata('Service removed. Deploy your apps to drop its URL from them.');
        } catch (RuntimeException $e) {
            set_flashdata(htmlspecialchars($e->getMessage()));
        }

        redirect('projects/' . $project->id . '/services');
    }

    /**
     * The Add service form's values, with the defaults for an empty port or memory
     *
     * @return array{name: string, image: string, port: int, memory_mb: int, env: string}
     */
    function _service_form(): array {
        $name = strtolower(trim((string) post('service_name', true)));
        $image = trim((string) post('service_image', true));
        $port = trim((string) post('service_port', true));
        $memory = trim((string) post('service_memory_mb', true));

        return [
            'name' => $name,
            'image' => $image,
            'port' => $port !== '' ? (int) $port : Project_service::DEFAULT_PORT,
            'memory_mb' => $memory !== '' ? (int) $memory : Project_service::DEFAULT_MEMORY_MB,
            'env' => (string) post('service_env'),
        ];
    }

    /**
     * KEY=value lines (App_env::parse_lines)
     *
     * @return array<string, string>
     * @throws InvalidArgumentException
     */
    function _parse_service_env(string $text): array {
        $env = App_env::parse_lines($text);

        if (count($env) > App_env::MAX_VARIABLES) {
            throw new InvalidArgumentException('A service can have at most ' . App_env::MAX_VARIABLES . ' environment variables.');
        }

        return $env;
    }

    /**
     * Form posts only; the CSRF token is checked by the validation run that follows.
     */
    /**
     * The project's deleted apps with a backup, ready for the "Deleted apps"
     * card on the project page (views/_app_backups.php)
     *
     * @return list<array{name: string, pill_class: string, status: string, detail: string, downloads: array<string, string>}>
     */
    function _app_backup_rows(int $project_id): array {
        $rows = [];

        foreach ($this->model->project_app_backups($project_id) as $backup) {
            $rows[] = $this->_app_backup_row($backup);
        }

        return $rows;
    }

    function _app_backup_row(object $backup): array {
        $name = (string) $backup->label;

        if ($backup->environment === 'staging') {
            $name .= ' (staging)';
        }

        $row = [
            'name' => $name,
            'pill_class' => '',
            'status' => '',
            'detail' => '',
            'downloads' => [],
        ];

        if ($backup->status === 'backing_up') {
            $row['status'] = 'Backing up';
            $row['pill_class'] = 'status-building';
            $row['detail'] = 'Its database and files are being saved. They can be downloaded here once that is done.';
        }

        if ($backup->status === 'failed') {
            $row['status'] = 'Backup failed';
            $row['pill_class'] = 'status-failed';
            $row['detail'] = 'Its database and files could not be saved, so nothing was deleted. We have been told and will look into it.';
        }

        if ($backup->status === 'ready') {
            $expires_at = strtotime((string) $backup->expires_at);
            $row['status'] = 'Ready';
            $row['pill_class'] = 'status-success';
            $row['detail'] = 'Downloadable until ' . gmdate('j M Y, H:i', $expires_at) . ' UTC. Then it is deleted for good.';
            $row['downloads'] = [
                'Database (' . $this->_size_label($backup->database_bytes) . ')' => 'apps/' . (int) $backup->app_id . '/backup/database',
                'Files (' . $this->_size_label($backup->files_bytes) . ')' => 'apps/' . (int) $backup->app_id . '/backup/files',
            ];
        }

        return $row;
    }

    /** "12.4 MB", or "size unknown" when the bucket did not say */
    function _size_label(mixed $bytes): string {
        if ($bytes === null) {
            return 'size unknown';
        }

        $bytes = (int) $bytes;

        if ($bytes < 1024 * 1024) {
            return max(1, (int) round($bytes / 1024)) . ' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
    }

    function _require_post(): void {
        if (REQUEST_TYPE !== 'POST') {
            http_response_code(405);
            die('Method not allowed');
        }
    }
}
