<?php
require_once APPPATH . 'modules/platform/live/Cluster_overview.php';

/**
 * tg-admin's first page: each node's pressure and every app and service at a
 * glance, read live from the cluster on each request (Cluster_overview) and
 * refreshed in place while the page is open. Nothing is stored.
 *
 * @property-read Overview_model $model
 * @property-read Templates $templates
 * @property-read Trongate_security $trongate_security
 */
class Overview extends Trongate {

    function index(): void {
        $this->trongate_security->make_sure_allowed();

        $data = $this->_live_data();
        $data['counts'] = $this->model->counts();
        $data['page_title'] = 'Overview';
        $data['view_module'] = 'overview';
        $data['view_file'] = 'index';
        $data['additional_includes_top'] = ['overview_module/css/overview.css'];
        $this->templates->admin($data);
    }

    /**
     * MX partial polled by the page: the nodes, apps and services again
     */
    function live(): void {
        $this->trongate_security->make_sure_allowed();
        $this->view('_live', $this->_live_data());
    }

    /**
     * @return array{cluster: ?array, apps: list<object>, services: list<object>}
     */
    function _live_data(): array {
        try {
            $cluster = Cluster_overview::read(Kubernetes::from_env());
        } catch (Throwable $e) {
            // Local development, or the API server is unreachable
            $cluster = null;
        }

        return [
            'cluster' => $cluster,
            'apps' => $this->model->apps(),
            'services' => $this->model->services(),
        ];
    }
}
