<?php

/**
 * @property-read Db $db
 */
class Metrics_model extends Model {

    /**
     * Customer (project) worker task counts keyed by state (pending, running, failed).
     * Periodic tasks have their own gauges, so a recurring failure there does
     * not pile up here.
     */
    function task_counts(): array {
        $sql = <<<SQL
            SELECT
                SUM(started_at IS NULL AND completed_at IS NULL AND failed_at IS NULL) AS pending,
                SUM(started_at IS NOT NULL AND completed_at IS NULL AND failed_at IS NULL) AS running,
                SUM(failed_at IS NOT NULL) AS failed
            FROM tasks
            WHERE project_id IS NOT NULL
        SQL;
        return $this->db->query($sql, 'array')[0] ?? [];
    }

    /**
     * Deployment counts keyed by status
     */
    function deployment_counts(): array {
        $sql = <<<SQL
            SELECT status, COUNT(*) AS total
            FROM deployment
            GROUP BY status
        SQL;
        return array_column($this->db->query($sql, 'array'), 'total', 'status');
    }

    /**
     * Number of apps currently live (have a current deployment)
     */
    function live_app_count(): int {
        $sql = <<<SQL
            SELECT COUNT(*) FROM app WHERE current_deployment_id IS NOT NULL AND deleted_at IS NULL
        SQL;
        return (int) $this->db->query($sql, 'column');
    }

    /**
     * Periodic worker tasks (Runtime::PERIODIC_TASKS): when each last finished
     * and last failed (unix time) and its last run's summary.
     *
     * Empty when the table is missing (schema applied by hand), which must
     * not take the other gauges down with it.
     */
    function scheduled_jobs(): array {
        $sql = <<<SQL
            SELECT script, UNIX_TIMESTAMP(last_finished_at) AS last_finished, UNIX_TIMESTAMP(last_failed_at) AS last_failed, last_summary
            FROM scheduled_jobs
            ORDER BY script
        SQL;

        try {
            return $this->db->query($sql, 'array');
        } catch (PDOException) {
            return [];
        }
    }
}
