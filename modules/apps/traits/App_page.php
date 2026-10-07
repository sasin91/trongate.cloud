<?php

require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once APPPATH . 'modules/platform/traits/Live_app_state.php';

/**
 * What every app page needs for its header (views/_app_header.php) and breadcrumb.
 * Shared by Apps and its child modules (apps-database).
 *
 * The controller's model must use the Platform_actions trait.
 */
trait App_page {
    use Live_app_state;

    /**
     * $urls, $can_rollback and $live_state for _app_header.php
     *
     * @return array{urls: list<string>, can_rollback: bool, live_state: array{state: string, label: string, detail: string}|null}
     */
    function _app_header_data(object $app): array {
        return [
            'urls' => $this->_app_urls($app),
            'can_rollback' => $this->model->_previous_successful_deployment($app) !== false,
            'live_state' => $this->_live_app_state($app),
        ];
    }

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
     * Public URLs of an app: the platform subdomain first, then the custom domain.
     *
     * @return list<string>
     */
    function _app_urls(object $app): array {
        return array_map(fn(string $host) => 'https://' . $host, Manifests::hostnames((array) $app));
    }
}
