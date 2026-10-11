<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/apps/traits/App_page.php';
require_once APPPATH . 'modules/platform/live/App_traffic.php';

/**
 * Child module of apps: apps/{id}/traffic, the app's requests as they happen
 * (App_traffic, read from Traefik's access log). The page polls poll() and
 * keeps everything in the browser: stats, the request list and the CSV
 * download are built there, nothing is stored. Every member of the app's
 * project may use it, like logs.
 *
 * @property-read Traffic_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Templates $templates
 */
class Traffic extends Trongate {
    use Customer_guard;
    use App_page;

    /**
     * apps/{id}/traffic
     */
    function index(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $data = [
            'view_module' => 'apps/traffic',
            'view_file' => 'index',
            'app' => $app,
            ...$this->_app_header_data($app),
            'crumbs' => [...$this->_app_crumbs($app), ['apps/' . $app->id . '/traffic', 'Traffic']],
            'additional_includes_top' => ['deployments_module/css/deployments.css', 'apps_module/css/apps.css'],
            'additional_includes_btm' => ['apps_module/js/app_traffic.js'],
        ];
        $this->module('templates');
        $this->templates->customer_wide($data);
    }

    /**
     * JSON polled by the page: requests newer than ?since= and the next cursor
     */
    function poll(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $app = $this->model->find_member_app((int) $customer->id, segment(3, 'int'));

        if ($app === false) {
            http_response_code(404);
            return;
        }

        $this->_send_traffic(App_traffic::app_pattern($app));
    }
}
