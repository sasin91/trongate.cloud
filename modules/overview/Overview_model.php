<?php

/**
 * The rows the tg-admin overview lists next to their live state
 *
 * @property-read Db $db
 */
class Overview_model extends Model {

    /**
     * Apps that are not deleted, with their project and last deployment
     *
     * @return list<object>
     */
    function apps(): array {
        $sql = <<<SQL
            SELECT a.id, a.label, a.slug, r.always_on, a.project_id, p.name AS project_name,
                   d.status AS deployment_status, d.created_at AS deployed_at,
                   (SELECT COUNT(*) FROM app_process ap WHERE ap.app_id = a.id) AS processes
            FROM app a
            JOIN project p ON p.id = a.project_id
            LEFT JOIN app_runtime r ON r.app_id = a.id
            LEFT JOIN deployment d ON d.id = (SELECT MAX(id) FROM deployment WHERE app_id = a.id)
            WHERE a.deleted_at IS NULL
            ORDER BY p.name, a.label
        SQL;

        return $this->db->query($sql, 'object');
    }

    /**
     * Services that are not deleted, with their project
     *
     * @return list<object>
     */
    function services(): array {
        $sql = <<<SQL
            SELECT s.id, s.name, s.image, s.memory_mb, s.project_id, p.name AS project_name
            FROM service s
            JOIN project p ON p.id = s.project_id
            WHERE s.deleted_at IS NULL
            ORDER BY p.name, s.name
        SQL;

        return $this->db->query($sql, 'object');
    }

    /**
     * @return array{customers: int, projects: int, apps: int, failed_tasks: int}
     */
    function counts(): array {
        $sql = <<<SQL
            SELECT (SELECT COUNT(*) FROM customer) AS customers,
                   (SELECT COUNT(*) FROM project WHERE deleted_at IS NULL) AS projects,
                   (SELECT COUNT(*) FROM app WHERE deleted_at IS NULL) AS apps,
                   (SELECT COUNT(*) FROM tasks WHERE failed_at > NOW() - INTERVAL 1 DAY) AS failed_tasks
        SQL;
        $row = $this->db->query($sql, 'object')[0] ?? null;

        return [
            'customers' => (int) ($row->customers ?? 0),
            'projects' => (int) ($row->projects ?? 0),
            'apps' => (int) ($row->apps ?? 0),
            'failed_tasks' => (int) ($row->failed_tasks ?? 0),
        ];
    }
}
