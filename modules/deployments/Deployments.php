<?php
require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once __DIR__ . '/Deploy_steps.php';

/**
 * Deployment detail page: the deploy's steps as a timeline (Deploy_steps),
 * with its live log in a modal. Any member of the deployment's project may
 * read it.
 *
 * @property-read Deployments_model $model
 * @property-read Templates $templates
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Validation $validation
 */
class Deployments extends Trongate {
    use Customer_guard;

    const FINISHED = ['success', 'failed', 'rolled_back'];

    function show(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();

        $deployment = $this->_find_or_redirect((int) $customer->id, segment(3, 'int'));
        $logs = $this->model->deployment_logs((int) $deployment->project_id, (int) $deployment->id, 0, 1000);
        $marks = $this->model->deployment_step_marks((int) $deployment->project_id, (int) $deployment->id);
        $steps = Deploy_steps::steps($this->_app_row((int) $customer->id, $deployment), (array) $deployment, $marks);

        $data = [
            'view_module' => 'deployments',
            'view_file' => 'show',
            'deployment' => $deployment,
            'steps' => $steps,
            'step_now' => Deploy_steps::now($steps),
            'last_log_id' => $this->_last_log_id($logs, 0),
            'is_finished' => in_array($deployment->status, self::FINISHED, true),
            'failure' => $this->_failure($deployment),
            'poll_swaps' => $this->_poll_swaps(),
            'additional_includes_top' => ['deployments_module/css/deployments.css'],
            'additional_includes_btm' => ['deployments_module/js/deploy_log.js'],
        ];

        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * The log, opened in an MX modal from the Steps card (_log_modal.php).
     * While the deployment runs, the modal polls logs_partial for new lines
     * (deploy_log.js starts it once the modal is built).
     */
    function log_modal(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();

        $deployment = $this->model->find_member_deployment((int) $customer->id, segment(3, 'int'));

        if ($deployment === false) {
            http_response_code(404);
            return;
        }

        $logs = $this->model->deployment_logs((int) $deployment->project_id, (int) $deployment->id, 0, 1000);

        $this->view('_log_modal', [
            'deployment' => $deployment,
            'logs' => Deploy_steps::without_marks($logs),
            'last_log_id' => $this->_last_log_id($logs, 0),
            'is_finished' => in_array($deployment->status, self::FINISHED, true),
            'poll_swaps' => $this->_log_poll_swaps(),
        ]);
    }

    /**
     * HTML partial polled by MX: new log lines, the steps' states, the log
     * cursor and the status pill (see _logs_partial.php).
     */
    function logs_partial(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();

        $deployment = $this->model->find_member_deployment((int) $customer->id, segment(3, 'int'));

        if ($deployment === false) {
            http_response_code(404);
            return;
        }

        $last_log_id = max(0, (int) ($_GET['last_log_id'] ?? 0));
        $logs = $this->model->deployment_logs((int) $deployment->project_id, (int) $deployment->id, $last_log_id);
        $marks = $this->model->deployment_step_marks((int) $deployment->project_id, (int) $deployment->id);
        $steps = Deploy_steps::steps($this->_app_row((int) $customer->id, $deployment), (array) $deployment, $marks);

        $this->view('_logs_partial', [
            'deployment' => $deployment,
            'steps' => $steps,
            'step_now' => Deploy_steps::now($steps),
            'logs' => Deploy_steps::without_marks($logs),
            'last_log_id' => $this->_last_log_id($logs, $last_log_id),
            'is_finished' => in_array($deployment->status, self::FINISHED, true),
            'failure' => $this->_failure($deployment),
        ]);
    }

    /**
     * The deployment's app with its settings, for the steps; only its id and
     * slug once the app is deleted
     */
    function _app_row(int $customer_id, object $deployment): array {
        $app = $this->model->find_member_app($customer_id, (int) $deployment->app_id);

        if ($app === false) {
            return ['id' => (int) $deployment->app_id, 'slug' => (string) $deployment->app_slug];
        }

        return (array) $app;
    }

    /**
     * The page poller's mx-select-oob (show.php): each step's heading
     * replaced, then the cursor, status, failure card and the stop signal
     * (_logs_partial.php). The log lines are for the Logs modal.
     */
    function _poll_swaps(): string {
        $swaps = [];

        foreach (Deploy_steps::KEYS as $key) {
            $swaps[] = ['select' => "#step-head-{$key}", 'target' => "#step-head-{$key}", 'swap' => 'outerHTML'];
        }

        $swaps[] = ['select' => '#step-now', 'target' => '#step-now', 'swap' => 'outerHTML'];
        $swaps[] = ['select' => '#log-cursor', 'target' => '#log-poller', 'swap' => 'value'];
        $swaps[] = ['select' => '#deployment-status', 'target' => '#deployment-status', 'swap' => 'outerHTML'];
        $swaps[] = ['select' => '#deploy-failure', 'target' => '#deploy-failure', 'swap' => 'outerHTML'];
        $swaps[] = ['select' => '#log-poller-stop', 'target' => '.log-poller-keepalive, #log-poller', 'swap' => 'delete'];

        return (string) json_encode($swaps);
    }

    /**
     * The Logs modal poller's mx-select-oob (_log_modal.php): new lines
     * appended, its cursor moved, and once the deployment has finished its
     * keepalive goes, so the poller itself is deleted and stops
     */
    function _log_poll_swaps(): string {
        return (string) json_encode([
            ['select' => '#new-logs', 'target' => '#logs-content', 'swap' => 'beforeend'],
            ['select' => '#log-cursor', 'target' => '#log-modal-poller', 'swap' => 'value'],
            ['select' => '#log-modal-keepalive', 'target' => '#log-modal-keepalive', 'swap' => 'outerHTML'],
            ['select' => '#log-poller-stop', 'target' => '.log-modal-running, #log-modal-poller', 'swap' => 'delete'],
        ]);
    }

    /**
     * Find a deployment of one of the customer's projects, or redirect to the dashboard
     */
    function _find_or_redirect(int $customer_id, int $deployment_id): object {
        $deployment = $this->model->find_member_deployment($customer_id, $deployment_id);

        if ($deployment === false) {
            redirect('dashboard');
        }

        return $deployment;
    }

    /**
     * Why a failed deployment failed and how to recover (Config_error::to_array),
     * with a link to the file and line on GitHub at the deployment's commit;
     * null when it has none
     *
     * @return ?array{reason: string, action: string, hint: string, file: ?string, line: ?int, url: ?string}
     */
    function _failure(object $deployment): ?array {
        $failure = $deployment->status === 'failed' ? json_decode((string) ($deployment->failure ?? ''), true) : null;

        if (!is_array($failure) || !isset($failure['reason'], $failure['action'], $failure['hint'])) {
            return null;
        }

        $failure += ['file' => null, 'line' => null];
        $source = (string) preg_replace('/\.git$/', '', rtrim((string) $deployment->source_url, '/'));
        $commit = (string) $deployment->commit_sha;
        $failure['url'] = $failure['file'] !== null && str_starts_with($source, 'https://github.com/') && preg_match('/^[0-9a-f]{40}$/', $commit)
            ? "{$source}/blob/{$commit}/config/" . rawurlencode((string) $failure['file']) . ($failure['line'] !== null ? '#L' . (int) $failure['line'] : '')
            : null;

        return $failure;
    }

    function _last_log_id(array $logs, int $default): int {
        return empty($logs) ? $default : (int) end($logs)->id;
    }
}
