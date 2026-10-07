<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/platform/traits/Live_app_state.php';

/**
 * Projects: a group of apps sharing one namespace, and the people who work on them.
 * Every member sees and deploys all of a project's apps; the owner also renames
 * and deletes the project and manages its members.
 *
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
     * Form posts only; the CSRF token is checked by the validation run that follows.
     */
    function _require_post(): void {
        if (REQUEST_TYPE !== 'POST') {
            http_response_code(405);
            die('Method not allowed');
        }
    }
}
