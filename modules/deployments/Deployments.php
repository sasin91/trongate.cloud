<?php
require_once APPPATH . 'modules/customer/traits/Customer_guard.php';

/**
 * Deployment detail page with live build/rollout logs.
 *
 * @property-read Deployments_model $model
 * @property-read Templates $templates
 * @property-read Trongate_tokens $trongate_tokens
 */
class Deployments extends Trongate {
    use Customer_guard;

    const FINISHED = ['success', 'failed', 'rolled_back'];

    function show(): void {
        $this->_make_sure_allowed();
        $project_id = $this->_project_id_or_redirect();

        $deployment = $this->_find_or_redirect($project_id, segment(3, 'int'));
        $logs = $this->model->deployment_logs($project_id, (int) $deployment->id, 0, 1000);

        $data = [
            'view_module' => 'deployments',
            'view_file' => 'show',
            'deployment' => $deployment,
            'logs' => $logs,
            'last_log_id' => $this->_last_log_id($logs, 0),
            'is_finished' => in_array($deployment->status, self::FINISHED, true),
            'additional_includes_top' => ['deployments_module/css/deployments.css'],
        ];

        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * HTML partial polled by MX: new log lines, the log cursor and the status pill.
     */
    function logs_partial(): void {
        $this->_make_sure_allowed();
        $project_id = $this->_project_id_or_redirect();

        $deployment = $this->model->find_project_deployment($project_id, segment(3, 'int'));

        if ($deployment === false) {
            http_response_code(404);
            return;
        }

        $last_log_id = max(0, (int) ($_GET['last_log_id'] ?? 0));
        $logs = $this->model->deployment_logs($project_id, (int) $deployment->id, $last_log_id);

        $this->view('_logs_partial', [
            'deployment' => $deployment,
            'logs' => $logs,
            'last_log_id' => $this->_last_log_id($logs, $last_log_id),
            'is_finished' => in_array($deployment->status, self::FINISHED, true),
        ]);
    }

    /**
     * Find a deployment belonging to the project, or redirect to the dashboard
     */
    function _find_or_redirect(int $project_id, int $deployment_id): object {
        $deployment = $this->model->find_project_deployment($project_id, $deployment_id);

        if ($deployment === false) {
            redirect('dashboard');
        }

        return $deployment;
    }

    function _last_log_id(array $logs, int $default): int {
        return empty($logs) ? $default : (int) end($logs)->id;
    }
}
