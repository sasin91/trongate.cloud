<?php

/**
 * @property-read Metrics_model $model
 */
class Metrics extends Trongate {

    /**
     * GET /metrics
     * Prometheus metrics endpoint, restricted to localhost and the Docker network
     */
    function index() {
        if (!$this->_is_prometheus_request()) {
            http_response_code(403);
            echo "Forbidden\n";
            exit;
        }

        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        echo $this->_collect_metrics();
        exit;
    }

    /**
     * Only allow localhost or the Docker bridge/custom network range (172.17-172.31)
     */
    function _is_prometheus_request(): bool {
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($client_ip === '127.0.0.1' || $client_ip === '::1') {
            return true;
        }

        return (bool) preg_match('/^172\.(1[7-9]|2[0-9]|3[01])\./', $client_ip);
    }

    /**
     * Cheap DB gauges in Prometheus text format
     */
    function _collect_metrics(): string {
        $tasks = $this->model->task_counts();

        $output = "# HELP app_tasks Worker tasks by state\n";
        $output .= "# TYPE app_tasks gauge\n";
        foreach (['pending', 'running', 'failed'] as $state) {
            $count = (int) ($tasks[$state] ?? 0);
            $output .= "app_tasks{state=\"{$state}\"} {$count}\n";
        }

        $counts = $this->model->deployment_counts();

        $output .= "\n# HELP app_deployments Deployments by status\n";
        $output .= "# TYPE app_deployments gauge\n";
        foreach (['pending', 'building', 'deploying', 'success', 'failed', 'rolled_back'] as $status) {
            $count = (int) ($counts[$status] ?? 0);
            $output .= "app_deployments{status=\"{$status}\"} {$count}\n";
        }

        $live = $this->model->live_project_count();

        $output .= "\n# HELP app_live_projects Projects with a live deployment\n";
        $output .= "# TYPE app_live_projects gauge\n";
        $output .= "app_live_projects {$live}\n";

        $jobs = $this->model->scheduled_jobs();

        $output .= "\n# HELP app_periodic_task_last_run_timestamp_seconds When each periodic worker task last finished\n";
        $output .= "# TYPE app_periodic_task_last_run_timestamp_seconds gauge\n";
        foreach ($jobs as $job) {
            if ($job['last_finished'] !== null) {
                $output .= "app_periodic_task_last_run_timestamp_seconds{task=\"{$job['script']}\"} " . (int) $job['last_finished'] . "\n";
            }
        }

        // The last reconcile_deploys run; recovered > 0 means a GitHub push webhook was missed
        $reconcile = json_decode((string) (array_column($jobs, 'last_summary', 'script')['reconcile_deploys'] ?? ''), true);
        $outcomes = is_array($reconcile) ? ($reconcile['outcomes'] ?? []) : [];

        $output .= "\n# HELP app_deploy_reconcile_heads Branch heads the last deploy reconcile run checked, by outcome\n";
        $output .= "# TYPE app_deploy_reconcile_heads gauge\n";
        foreach (is_array($outcomes) ? $outcomes : [] as $outcome => $count) {
            if (preg_match('/^[a-z-]+$/', (string) $outcome)) {
                $output .= "app_deploy_reconcile_heads{outcome=\"{$outcome}\"} " . (int) $count . "\n";
            }
        }

        // Heads undeployed over 15 minutes that nothing will deploy (Deploy_reconcile::STUCK_OUTCOMES)
        if (is_array($reconcile)) {
            $output .= "\n# HELP app_deploy_reconcile_stuck_heads Branch heads the last deploy reconcile run found stuck undeployed\n";
            $output .= "# TYPE app_deploy_reconcile_stuck_heads gauge\n";
            $output .= 'app_deploy_reconcile_stuck_heads ' . (int) ($reconcile['stuck'] ?? 0) . "\n";
        }

        return $output;
    }
}
