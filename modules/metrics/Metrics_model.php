<?php

/**
 * @property-read Db $db
 */
class Metrics_model extends Model {

    /**
     * Worker task counts keyed by state (pending, running, failed)
     */
    function task_counts(): array {
        $sql = <<<SQL
            SELECT
                SUM(started_at IS NULL AND completed_at IS NULL AND failed_at IS NULL) AS pending,
                SUM(started_at IS NOT NULL AND completed_at IS NULL AND failed_at IS NULL) AS running,
                SUM(failed_at IS NOT NULL) AS failed
            FROM tasks
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
     * Number of projects currently live (have a current deployment)
     */
    function live_project_count(): int {
        $sql = <<<SQL
            SELECT COUNT(*) FROM project WHERE current_deployment_id IS NOT NULL AND deleted_at IS NULL
        SQL;
        return (int) $this->db->query($sql, 'column');
    }
}
