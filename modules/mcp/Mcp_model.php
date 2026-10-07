<?php

require_once __DIR__ . '/../platform/traits/Platform_actions.php';
require_once __DIR__ . '/../apps/traits/App_records.php';
require_once __DIR__ . '/../apps/database/traits/App_database_log.php';
require_once __DIR__ . '/traits/Mcp_grants.php';

/**
 * Data access for the MCP endpoint. Customer tools go through Platform_actions'
 * member lookups (find_member_app, ...); the admin sees every project: its
 * tools look up the owning project of an app or deployment, then go through
 * Platform_actions with that project id.
 *
 * @property-read Db $db
 */
class Mcp_model extends Model {
    use Platform_actions;
    use App_records;
    use App_database_log;
    use Mcp_grants;

    /**
     * An active, onboarded customer
     */
    function find_customer(int $customer_id): object|false {
        $rows = $this->db->query_bind(
            'SELECT * FROM customer WHERE id = :id AND active = 1 AND onboarded_at IS NOT NULL LIMIT 1',
            ['id' => $customer_id],
            'object'
        );

        return $rows[0] ?? false;
    }

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
     * Id, project and namespace of every app that is not deleted (all projects).
     */
    function live_app_refs(): array {
        $sql = <<<SQL
            SELECT a.id, a.project_id, p.namespace
            FROM app a
            JOIN project p ON p.id = a.project_id
            WHERE a.deleted_at IS NULL
        SQL;
        return $this->db->query($sql, 'array');
    }

    /**
     * Deleted apps that may still be on the cluster: their latest
     * destroy_app task failed, has not finished, or was never queued.
     * Each row says how far the last attempt got (all projects).
     */
    function unfinished_cleanups(): array {
        $sql = <<<SQL
            SELECT a.id AS app_id, a.project_id, a.label, a.slug, a.verified_domain, a.deleted_at,
                t.id AS task_id, t.started_at, t.failed_at, t.error_message
            FROM app a
            LEFT JOIN tasks t ON t.id = (
                SELECT MAX(lt.id) FROM tasks lt WHERE lt.app_id = a.id AND lt.script = 'destroy_app'
            )
            WHERE a.deleted_at IS NOT NULL
              AND t.completed_at IS NULL
            ORDER BY a.deleted_at ASC
        SQL;
        return $this->db->query($sql, 'object');
    }

    /**
     * Deleted projects whose namespace may still be on the cluster: their
     * latest destroy_project task failed, has not finished, or was never queued.
     */
    function unfinished_project_cleanups(): array {
        $sql = <<<SQL
            SELECT p.id AS project_id, p.name, p.namespace, p.deleted_at,
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
        $app_filter = $project_id === null ? 'WHERE a.deleted_at IS NULL' : 'WHERE a.project_id = :project_id AND a.deleted_at IS NULL';
        $deployment_filter = $project_id === null ? '' : 'AND d.project_id = :project_id';

        $sql = <<<SQL
            SELECT COALESCE(cd.status, 'never_deployed') AS status, COUNT(*) AS total
            FROM app a
            LEFT JOIN deployment cd ON cd.id = a.current_deployment_id
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
            SELECT d.id, d.project_id, d.app_id, a.label AS app_label, d.status, d.trigger_type, d.created_at
            FROM deployment d
            JOIN app a ON a.id = d.app_id
            WHERE d.status IN ('pending', 'building', 'deploying') {$deployment_filter}
            ORDER BY d.id DESC
            LIMIT 20
        SQL;
        $in_progress = $this->db->query_bind($sql, $params, 'object');

        $sql = <<<SQL
            SELECT d.id, d.project_id, d.app_id, a.label AS app_label, d.trigger_type, d.created_at, d.completed_at
            FROM deployment d
            JOIN app a ON a.id = d.app_id
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
