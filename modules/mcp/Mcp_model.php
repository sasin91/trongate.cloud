<?php

require_once __DIR__ . '/../platform/traits/Platform_actions.php';

/**
 * Data access for the admin MCP endpoint. Sees every project: tools look up the
 * owning project of a app or deployment, then go through Platform_actions
 * with that project id.
 *
 * @property-read Db $db
 */
class Mcp_model extends Model {
    use Platform_actions;

    /**
     * Project names keyed by id.
     */
    function project_names(): array {
        $rows = $this->db->query('SELECT id, name FROM project ORDER BY name ASC', 'object');

        return array_column($rows, 'name', 'id');
    }

    function app_project_id(int $app_id): int|false {
        $project_id = $this->db->query_bind('SELECT project_id FROM app WHERE id = :id', ['id' => $app_id], 'column');

        return $project_id === false ? false : (int) $project_id;
    }

    /**
     * Id and project of every app that is not deleted (all projects).
     */
    function live_app_refs(): array {
        return $this->db->query('SELECT id, project_id FROM app WHERE deleted_at IS NULL', 'array');
    }

    /**
     * Deleted apps whose app may still be on the cluster: their latest
     * destroy_app task failed, has not finished, or was never queued.
     * Each row says how far the last attempt got (all projects).
     */
    function unfinished_cleanups(): array {
        $sql = <<<SQL
            SELECT p.id AS app_id, p.project_id, p.label, p.slug, p.verified_domain, p.deleted_at,
                t.id AS task_id, t.started_at, t.failed_at, t.error_message
            FROM app p
            LEFT JOIN tasks t ON t.id = (
                SELECT MAX(lt.id) FROM tasks lt WHERE lt.app_id = p.id AND lt.script = 'destroy_app'
            )
            WHERE p.deleted_at IS NOT NULL
              AND t.completed_at IS NULL
            ORDER BY p.deleted_at ASC
        SQL;
        return $this->db->query($sql, 'object');
    }

    function deployment_project_id(int $deployment_id): int|false {
        $project_id = $this->db->query_bind('SELECT project_id FROM deployment WHERE id = :id', ['id' => $deployment_id], 'column');

        return $project_id === false ? false : (int) $project_id;
    }

    /**
     * Counts: apps by current deployment status, deployments by status,
     * deployments in progress and the latest failures. All projects, or one.
     */
    function platform_status(?int $project_id = null): array {
        $params = $project_id === null ? [] : ['project_id' => $project_id];
        $app_filter = $project_id === null ? 'WHERE p.deleted_at IS NULL' : 'WHERE p.project_id = :project_id AND p.deleted_at IS NULL';
        $deployment_filter = $project_id === null ? '' : 'AND d.project_id = :project_id';

        $sql = <<<SQL
            SELECT COALESCE(cd.status, 'never_deployed') AS status, COUNT(*) AS total
            FROM app p
            LEFT JOIN deployment cd ON cd.id = p.current_deployment_id
            {$app_filter}
            GROUP BY COALESCE(cd.status, 'never_deployed')
        SQL;
        $apps = $this->db->query_bind($sql, $params, 'object');

        $sql = <<<SQL
            SELECT d.status, COUNT(*) AS total
            FROM deployment d
            WHERE 1 = 1 {$deployment_filter}
            GROUP BY d.status
        SQL;
        $deployments = $this->db->query_bind($sql, $params, 'object');

        $sql = <<<SQL
            SELECT d.id, d.project_id, d.app_id, p.label AS app_label, d.status, d.trigger_type, d.created_at
            FROM deployment d
            JOIN app p ON p.id = d.app_id
            WHERE d.status IN ('pending', 'building', 'deploying') {$deployment_filter}
            ORDER BY d.id DESC
            LIMIT 20
        SQL;
        $in_progress = $this->db->query_bind($sql, $params, 'object');

        $sql = <<<SQL
            SELECT d.id, d.project_id, d.app_id, p.label AS app_label, d.trigger_type, d.created_at, d.completed_at
            FROM deployment d
            JOIN app p ON p.id = d.app_id
            WHERE d.status = 'failed' {$deployment_filter}
            ORDER BY d.id DESC
            LIMIT 5
        SQL;
        $recent_failures = $this->db->query_bind($sql, $params, 'object');

        return [
            'apps' => $apps,
            'deployments' => $deployments,
            'in_progress' => $in_progress,
            'recent_failures' => $recent_failures,
        ];
    }
}
