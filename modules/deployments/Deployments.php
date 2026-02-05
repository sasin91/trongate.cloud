<?php

/**
 * @property-read Deployments_model $model
 * @property-read Templates $templates
 * @property-read Trongate_tokens $trongate_tokens
 */
class Deployments extends Trongate {

    function show(): void {
        $this->_make_sure_allowed();

        $deployment_id = segment(3, 'int');
        $deployment = $this->model->find($deployment_id);

        if ($deployment === false) {
            redirect('customer');
        }

        // Verify user has access to this deployment
        $team_id = $this->model->get_customer_team_id();
        if ($deployment->team_id != $team_id) {
            redirect('customer');
        }

        $data = [
            'view_module' => 'deployments',
            'view_file' => 'show',
            'deployment' => $deployment
        ];

        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * JSON API endpoint for polling deployment status and logs.
     */
    function status(): void {
        $this->_make_sure_allowed();

        $deployment_id = segment(3, 'int');
        $last_log_id = (int) ($_GET['last_log_id'] ?? 0);

        $deployment = $this->model->find($deployment_id);

        if ($deployment === false) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Deployment not found']);
            return;
        }

        // Verify access
        $team_id = $this->model->get_customer_team_id();
        if ($deployment->team_id != $team_id) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        $logs = $this->model->get_logs_since($deployment->server_id, $last_log_id);
        $task = $this->model->get_task($deployment_id);
        $task_status = $this->_get_task_status($task);

        // Update deployment status based on task status
        if ($task_status === 'completed' && $deployment->status !== 'success') {
            $this->model->update_status($deployment_id, 'success');
            $deployment->status = 'success';
        } elseif ($task_status === 'failed' && $deployment->status !== 'failed') {
            $this->model->update_status($deployment_id, 'failed');
            $deployment->status = 'failed';
        } elseif ($task_status === 'running' && $deployment->status === 'pending') {
            $this->model->update_status($deployment_id, 'running');
            $deployment->status = 'running';
        }

        header('Content-Type: application/json');
        echo json_encode([
            'status' => $deployment->status,
            'task_status' => $task_status,
            'logs' => $logs,
            'completed' => in_array($deployment->status, ['success', 'failed'])
        ]);
    }

    /**
     * HTML partial for logs - used by mx-poll to append new logs.
     */
    function logs_partial(): void {
        $this->_make_sure_allowed();

        $deployment_id = segment(3, 'int');
        $last_log_id = (int) ($_GET['last_log_id'] ?? 0);

        $deployment = $this->model->find($deployment_id);

        if ($deployment === false) {
            return;
        }

        // Verify access
        $team_id = $this->model->get_customer_team_id();
        if ($deployment->team_id != $team_id) {
            return;
        }

        $logs = $this->model->get_logs_since($deployment->server_id, $last_log_id);
        $this->view('_logs_partial', ['logs' => $logs]);
    }

    /**
     * GitHub Actions setup instructions page.
     */
    function github_actions(): void {
        $this->_make_sure_allowed();

        $deployment_id = segment(3, 'int');
        $deployment = $this->model->find($deployment_id);

        if ($deployment === false) {
            redirect('customer');
        }

        // Verify access
        $team_id = $this->model->get_customer_team_id();
        if ($deployment->team_id != $team_id) {
            redirect('customer');
        }

        $project = $this->model->get_project($deployment->project_id);

        $data = [
            'view_module' => 'deployments',
            'view_file' => 'github_actions',
            'deployment' => $deployment,
            'project' => $project,
            'webhook_url' => BASE_URL . 'webhooks/github/' . $project->id
        ];

        $this->module('templates');
        $this->templates->customer($data);
    }

    private function _get_task_status(?object $task): string {
        if (!$task) return 'not_found';
        if ($task->completed_at) return 'completed';
        if ($task->failed_at) return 'failed';
        if ($task->started_at) return 'running';
        return 'pending';
    }

    function _make_sure_allowed(): void {
        $this->module('trongate_tokens');
        $token = $this->trongate_tokens->attempt_get_valid_token(2);

        if ($token === false) {
            redirect('customer/login');
        }
    }
}
