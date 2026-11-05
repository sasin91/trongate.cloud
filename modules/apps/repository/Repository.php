<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/apps/traits/App_page.php';
require_once APPPATH . 'modules/apps/traits/App_rules.php';
require_once APPPATH . 'modules/platform/git/Git_repo.php';

/**
 * Child module of apps: the app's repository.
 *
 *   apps/{id}/git                    where the app's code comes from; on
 *                                    trongate.cloud git: its clone address and
 *                                    access tokens (Git_tokens)
 *   projects/{id}/apps/new-git       a new app on trongate.cloud git, with an
 *                                    empty repository to push to
 *
 * Every member of the app's project may use it, like settings.
 *
 * @property-read Repository_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Templates $templates
 * @property-read Validation $validation
 */
class Repository extends Trongate {
    use Customer_guard;
    use App_page;
    use App_rules;

    /**
     * apps/{id}/git
     */
    function index(?string $new_token = null): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $repo = Git_repo::from_url((string) $app->source_url);
        $is_internal = $app->source_provider === Git_repo::PROVIDER && $repo !== null;

        $data = [
            'view_module' => 'apps/repository',
            'view_file' => 'index',
            'app' => $app,
            'is_internal' => $is_internal,
            'clone_url' => (string) $app->source_url,
            'branch' => (string) ($app->source_branch ?: 'main'),
            'tokens' => $is_internal && $repo !== null ? $this->model->app_git_tokens($repo->app_id()) : [],
            'new_token' => $new_token,
            ...$this->_app_header_data($app),
            'crumbs' => [...$this->_app_crumbs($app), ['apps/' . $app->id . '/git', 'Git']],
            'additional_includes_top' => ['apps_module/css/apps.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * POST apps/{id}/git/tokens/submit: a new access token, shown once
     */
    function create_token(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $customer = $this->_customer_or_redirect();
        $repo = Git_repo::from_url((string) $app->source_url);

        $this->validation->set_rules('label', 'Token name', 'max_length[60]');

        if ($repo === null || $this->validation->run() !== true) {
            $this->index();
            return;
        }

        $label = trim((string) post('label', true));
        $token = $this->model->create_git_token($repo->app_id(), (int) $customer->id, $label);

        $this->index($token);
    }

    /**
     * POST apps/{id}/git/tokens/revoke: the token stops working at once
     */
    function revoke_token(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $repo = Git_repo::from_url((string) $app->source_url);

        $this->validation->set_rules('token_id', 'Token', 'required|integer');

        if ($repo === null || $this->validation->run() !== true) {
            $this->index();
            return;
        }

        if ($this->model->revoke_git_token($repo->app_id(), (int) post('token_id'))) {
            set_flashdata('Token revoked.');
        }

        redirect('apps/' . $app->id . '/git');
    }

    /**
     * projects/{id}/apps/new-git: name the app, then push to its repository
     */
    function create(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));

        $data = [
            'view_module' => 'apps/repository',
            'view_file' => 'create',
            'project' => $project,
            'app_types' => self::APP_TYPES,
            'label' => (string) post('label', true),
            'type' => (string) (post('type', true) ?: 'trongate_v2'),
            'crumbs' => [['projects/' . $project->id, (string) $project->name], ['projects/' . $project->id . '/apps/new-git', 'New app on trongate.cloud git']],
            'additional_includes_top' => ['apps_module/css/apps.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * POST projects/{id}/apps/new-git/submit: create the app and its repository,
     * then show how to push to it with a first access token
     */
    function submit_create(): void {
        $this->_make_sure_allowed();
        $project = $this->_member_project_or_redirect(segment(3, 'int'));
        $customer = $this->_customer_or_redirect();

        $this->validation->set_rules('label', 'App Name', 'required|min_length[3]|max_length[50]');
        $this->validation->set_rules('type', 'App Type', 'required|callback__validate_app_type');

        if ($this->validation->run() !== true) {
            $this->create();
            return;
        }

        $app_id = $this->_create_internal_app((int) $project->id, (int) $customer->id);

        if ($app_id === null) {
            set_flashdata('The app could not be created, please try again.');
            redirect('projects/' . $project->id . '/apps/new-git');
        }

        set_flashdata('Your app is ready for its first push. Pushing to main deploys it.');
        redirect('apps/' . $app_id . '/git');
    }

    function _create_internal_app(int $project_id, int $customer_id): ?int {
        try {
            return $this->model->create_internal_app($project_id, trim((string) post('label', true)), (string) post('type', true));
        } catch (RuntimeException $e) {
            error_log("[git] New app on trongate.cloud git in project #{$project_id} by customer #{$customer_id} failed: {$e->getMessage()}");

            return null;
        }
    }
}
