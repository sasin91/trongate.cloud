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
            ...$this->_environment_data($app),
        ];
    }

    /**
     * Which environment the app is, for the header and overview:
     * $environment ('production' or 'staging'), $environments (the
     * Production/Staging switch, empty while it has no staging app),
     * $go_live_hint (its {slug} host is still its staging) and
     * $can_add_staging (a production app live on its domain, with no staging app).
     *
     * @return array{environment: string, environments: list<array{0: string, 1: string, 2: bool}>, go_live_hint: bool, can_add_staging: bool}
     */
    function _environment_data(object $app): array {
        if ($app->environment === 'staging') {
            $production_id = (int) $app->production_app_id;
            $staging_id = (int) $app->id;
        } else {
            $production_id = (int) $app->id;
            $staging = $this->model->find_staging_app($production_id);
            $staging_id = $staging === false ? 0 : (int) $staging->id;
        }

        $environments = [];

        if ($staging_id > 0) {
            $environments = [
                ['apps/' . $production_id, 'Production', $production_id === (int) $app->id],
                ['apps/' . $staging_id, 'Staging', $staging_id === (int) $app->id],
            ];
        }

        $has_domain = (string) ($app->domain ?? '') !== '';
        $environment = 'production';

        if ($app->environment === 'staging' || ($staging_id === 0 && !$has_domain)) {
            $environment = 'staging';
        }

        return [
            'environment' => $environment,
            'environments' => $environments,
            'go_live_hint' => $app->environment === 'production' && $environment === 'staging',
            'can_add_staging' => $app->environment === 'production' && $staging_id === 0 && Manifests::secured_domains((array) $app) !== [],
        ];
    }

    /**
     * Header breadcrumb: the app's project, then the app (a staging app: its production app, then Staging)
     *
     * @return list<array{0: string, 1: string}>
     */
    function _app_crumbs(object $app): array {
        if ($app->environment === 'staging') {
            return [
                ['projects/' . $app->project_id, (string) $app->project_name],
                ['apps/' . $app->production_app_id, (string) $app->label],
                ['apps/' . $app->id, 'Staging'],
            ];
        }

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
