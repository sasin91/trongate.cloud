<?php

require_once APPPATH . 'modules/platform/live/App_state.php';
require_once APPPATH . 'modules/platform/live/App_logs.php';
require_once APPPATH . 'modules/platform/live/App_traffic.php';

/**
 * Live app state, logs and traffic for controllers (see App_state, App_logs, App_traffic). Pages render without it when
 * the cluster cannot be reached, e.g. in local development.
 */
trait Live_app_state {

    /**
     * States of the project's apps by app id, or null when the cluster cannot be read
     *
     * @param object $project a project row with its namespace (Platform_actions::find_member_project)
     * @return array<int, array{state: string, label: string, detail: string}>|null
     */
    function _live_app_states(object $project): ?array {
        try {
            return App_state::for_project(Kubernetes::from_env(), Manifests::namespace_name($project));
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * State of one app, or null when the cluster cannot be read or it was never deployed
     *
     * @param object $app an app row with its project's namespace (Platform_actions::find_member_app)
     * @return array{state: string, label: string, detail: string}|null
     */
    function _live_app_state(object $app): ?array {
        if (empty($app->current_deployment_id)) {
            return null;
        }

        try {
            return App_state::for_app(Kubernetes::from_env(), Manifests::namespace_name($app), (int) $app->id);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * States of the app's processes by name (App_state::for_processes), or
     * null when the cluster cannot be read or it was never deployed
     *
     * @return array<string, array{state: string, label: string, detail: string}>|null
     */
    function _live_process_states(object $app): ?array {
        if (empty($app->current_deployment_id)) {
            return null;
        }

        try {
            return App_state::for_processes(Kubernetes::from_env(), Manifests::namespace_name($app), (int) $app->id);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * An app's state and log lines newer than $cursor (see App_logs::read),
     * or null when the cluster cannot be read or it was never deployed
     *
     * @return array{state: array{state: string, label: string, detail: string}, lines: list<array{time: string, pod: string, level: string, text: string}>, cursor: string, pods: int}|null
     */
    function _live_app_logs(object $app, string $cursor = ''): ?array {
        if (empty($app->current_deployment_id)) {
            return null;
        }

        try {
            return App_logs::read(Kubernetes::from_env(), Manifests::namespace_name($app), (int) $app->id, $cursor);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Requests whose router matches $pattern (App_traffic::app_pattern or
     * project_pattern) newer than $cursor, or null when they cannot be read
     *
     * @return array{requests: list<array{time: string, app_id: int, method: string, host: string, path: string, status: int, ms: float, bytes: int}>, cursor: string}|null
     */
    function _live_traffic(string $pattern, string $cursor = ''): ?array {
        try {
            return App_traffic::read(Kubernetes::from_env(), $pattern, $cursor);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Send a _live_traffic() read as the JSON the Traffic pages poll (app_traffic.js)
     */
    function _send_traffic(string $pattern): void {
        $traffic = $this->_live_traffic($pattern, (string) ($_GET['since'] ?? ''));

        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($traffic === null ? ['ok' => false] : ['ok' => true] + $traffic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * An app's entry in _live_app_states(): null when unknown or never deployed
     *
     * @param array<int, array{state: string, label: string, detail: string}>|null $states
     * @return array{state: string, label: string, detail: string}|null
     */
    function _live_state_of(?array $states, object $app): ?array {
        if ($states === null || empty($app->current_deployment_id)) {
            return null;
        }

        return $states[(int) $app->id] ?? App_state::_make('not_running');
    }
}
