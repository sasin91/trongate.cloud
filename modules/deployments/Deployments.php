<?php
require_once APPPATH . 'modules/customer/traits/Customer_guard.php';

/**
 * Deployment detail page with live build/rollout logs. Any member of
 * the deployment's project may read it.
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

        $data = [
            'view_module' => 'deployments',
            'view_file' => 'show',
            'deployment' => $deployment,
            'logs' => $logs,
            'last_log_id' => $this->_last_log_id($logs, 0),
            'is_finished' => in_array($deployment->status, self::FINISHED, true),
            'failure' => $this->_failure($deployment),
            'additional_includes_top' => ['deployments_module/css/deployments.css'],
        ];

        $this->module('templates');
        $this->templates->customer_wide($data);
    }

    /**
     * HTML partial polled by MX: new log lines, the log cursor and the status pill.
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

        $this->view('_logs_partial', [
            'deployment' => $deployment,
            'logs' => $logs,
            'last_log_id' => $this->_last_log_id($logs, $last_log_id),
            'is_finished' => in_array($deployment->status, self::FINISHED, true),
            'failure' => $this->_failure($deployment),
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
