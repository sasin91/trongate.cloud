<?php

require_once __DIR__ . '/../platform/traits/Platform_actions.php';

/**
 * Data access for the admin MCP endpoint. Sees every team: tools look up the
 * owning team of a project or deployment, then go through Platform_actions
 * with that team id.
 *
 * @property-read Db $db
 */
class Mcp_model extends Model {
    use Platform_actions;

    /**
     * Team names keyed by id.
     */
    function team_names(): array {
        $rows = $this->db->query('SELECT id, name FROM team ORDER BY name ASC', 'object');

        return array_column($rows, 'name', 'id');
    }

    function project_team_id(int $project_id): int|false {
        $team_id = $this->db->query_bind('SELECT team_id FROM project WHERE id = :id', ['id' => $project_id], 'column');

        return $team_id === false ? false : (int) $team_id;
    }

    /**
     * Id and team of every project that is not deleted (all teams).
     */
    function live_project_refs(): array {
        return $this->db->query('SELECT id, team_id FROM project WHERE deleted_at IS NULL', 'array');
    }

    /**
     * Deleted projects whose app may still be on the cluster: their latest
     * destroy_project task failed, has not finished, or was never queued.
     * Each row says how far the last attempt got (all teams).
     */
    function unfinished_cleanups(): array {
        $sql = <<<SQL
            SELECT p.id AS project_id, p.team_id, p.label, p.slug, p.verified_domain, p.deleted_at,
                t.id AS task_id, t.started_at, t.failed_at, t.error_message
            FROM project p
            LEFT JOIN tasks t ON t.id = (
                SELECT MAX(lt.id) FROM tasks lt WHERE lt.project_id = p.id AND lt.script = 'destroy_project'
            )
            WHERE p.deleted_at IS NOT NULL
              AND t.completed_at IS NULL
            ORDER BY p.deleted_at ASC
        SQL;
        return $this->db->query($sql, 'object');
    }

    function deployment_team_id(int $deployment_id): int|false {
        $team_id = $this->db->query_bind('SELECT team_id FROM deployment WHERE id = :id', ['id' => $deployment_id], 'column');

        return $team_id === false ? false : (int) $team_id;
    }

    /**
     * Counts: projects by current deployment status, deployments by status,
     * deployments in progress and the latest failures. All teams, or one.
     */
    function platform_status(?int $team_id = null): array {
        $params = $team_id === null ? [] : ['team_id' => $team_id];
        $project_filter = $team_id === null ? 'WHERE p.deleted_at IS NULL' : 'WHERE p.team_id = :team_id AND p.deleted_at IS NULL';
        $deployment_filter = $team_id === null ? '' : 'AND d.team_id = :team_id';

        $sql = <<<SQL
            SELECT COALESCE(cd.status, 'never_deployed') AS status, COUNT(*) AS total
            FROM project p
            LEFT JOIN deployment cd ON cd.id = p.current_deployment_id
            {$project_filter}
            GROUP BY COALESCE(cd.status, 'never_deployed')
        SQL;
        $projects = $this->db->query_bind($sql, $params, 'object');

        $sql = <<<SQL
            SELECT d.status, COUNT(*) AS total
            FROM deployment d
            WHERE 1 = 1 {$deployment_filter}
            GROUP BY d.status
        SQL;
        $deployments = $this->db->query_bind($sql, $params, 'object');

        $sql = <<<SQL
            SELECT d.id, d.team_id, d.project_id, p.label AS project_label, d.status, d.trigger_type, d.created_at
            FROM deployment d
            JOIN project p ON p.id = d.project_id
            WHERE d.status IN ('pending', 'building', 'deploying') {$deployment_filter}
            ORDER BY d.id DESC
            LIMIT 20
        SQL;
        $in_progress = $this->db->query_bind($sql, $params, 'object');

        $sql = <<<SQL
            SELECT d.id, d.team_id, d.project_id, p.label AS project_label, d.trigger_type, d.created_at, d.completed_at
            FROM deployment d
            JOIN project p ON p.id = d.project_id
            WHERE d.status = 'failed' {$deployment_filter}
            ORDER BY d.id DESC
            LIMIT 5
        SQL;
        $recent_failures = $this->db->query_bind($sql, $params, 'object');

        return [
            'projects' => $projects,
            'deployments' => $deployments,
            'in_progress' => $in_progress,
            'recent_failures' => $recent_failures,
        ];
    }
}
