<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/apps/traits/App_page.php';

/**
 * Child module of apps: apps/{id}/upgrade, open a pull request on the app's
 * repository that moves it to the newest Trongate v2 release (App_upgrades,
 * tasks/upgrade_app.php). Every member of the app's project may use it, like
 * deploy.
 *
 * @property-read Upgrade_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Templates $templates
 */
class Upgrade extends Trongate {
    use Customer_guard;
    use App_page;

    /**
     * apps/{id}/upgrade: the latest check and its pull request
     */
    function index(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $data = [
            'view_module' => 'apps/upgrade',
            'view_file' => 'index',
            'app' => $app,
            ...$this->_app_header_data($app),
            ...$this->_upgrade_status($app),
            'crumbs' => [...$this->_app_crumbs($app), ['apps/' . $app->id . '/upgrade', 'Upgrade']],
            'additional_includes_top' => ['apps_module/css/apps.css'],
        ];
        $this->module('templates');
        $this->templates->customer_wide($data);
    }

    /**
     * apps/{id}/upgrade/status: _status.php, polled by the page while a check runs
     */
    function status(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $app = $this->model->find_member_app((int) $customer->id, segment(3, 'int'));

        if ($app === false) {
            http_response_code(404);
            return;
        }

        $this->view('_status', ['app' => $app, ...$this->_upgrade_status($app)]);
    }

    /**
     * POST apps/{id}/upgrade/submit: queue a check, then back to the page
     */
    function submit(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $customer = $this->_customer_or_redirect();

        try {
            $this->model->queue_upgrade((int) $app->project_id, (int) $app->id, (int) $customer->id);
        } catch (RuntimeException $e) {
            set_flashdata('Could not check for an upgrade: ' . htmlspecialchars($e->getMessage()));
        }

        redirect('apps/' . $app->id . '/upgrade');
    }

    /**
     * $upgrade and $checking for _status.php
     *
     * @return array{upgrade: object|false, checking: bool}
     */
    function _upgrade_status(object $app): array {
        $upgrade = $this->model->latest_upgrade((int) $app->id);

        return [
            'upgrade' => $upgrade,
            'checking' => $upgrade !== false && in_array($upgrade->status, ['pending', 'running'], true),
        ];
    }
}
