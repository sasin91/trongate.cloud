<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/apps/traits/App_page.php';
require_once APPPATH . 'modules/platform/git/Git_repo.php';

/**
 * Child module of apps: where the app's code comes from.
 *
 *   apps/{id}/git          the app's source; a production app on GitHub can
 *                          move to trongate.cloud git from here
 *   apps/{id}/git/move     POST: queue the move (tasks/import_git.php)
 *
 * Only the platform writes trongate.cloud git: customers do not clone or push
 * to it yet. Every member of the app's project may use it, like settings.
 *
 * @property-read Repository_model $model
 * @property-read Templates $templates
 * @property-read Validation $validation
 */
class Repository extends Trongate {
    use Customer_guard;
    use App_page;

    /**
     * apps/{id}/git
     */
    function index(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $repo = Git_repo::from_url((string) $app->source_url);
        $is_internal = $app->source_provider === Git_repo::PROVIDER && $repo !== null;

        $data = [
            'view_module' => 'apps/repository',
            'view_file' => 'index',
            'app' => $app,
            'is_internal' => $is_internal,
            'repo_name' => $repo?->name,
            'source_url' => (string) $app->source_url,
            'branch' => (string) ($app->source_branch ?: 'main'),
            'can_move' => !$is_internal && $app->source_provider === 'github' && $app->environment === 'production',
            'move_waiting' => $this->model->git_import_waiting((int) $app->id),
            ...$this->_app_header_data($app),
            'crumbs' => [...$this->_app_crumbs($app), ['apps/' . $app->id . '/git', 'Git']],
            'additional_includes_top' => ['apps_module/css/apps.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * POST apps/{id}/git/move: copy the GitHub repository to trongate.cloud git
     * and build from there
     */
    function move(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $customer = $this->_customer_or_redirect();

        $this->validation->set_rules('confirm', 'Confirmation', 'required');

        if ($this->validation->run() !== true) {
            $this->index();
            return;
        }

        $this->model->queue_git_import((int) $app->project_id, (int) $app->id, (int) $customer->id);
        set_flashdata('Moving to trongate.cloud git. A deploy from the copied repository follows.');
        redirect('apps/' . $app->id . '/git');
    }
}
