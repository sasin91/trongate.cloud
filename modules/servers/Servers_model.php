<?php
require_once APPPATH . 'modules/customer/traits/Current_customer.php';

/**
 * @property-read Db $db
 */
class Servers_model extends Model {
    use Current_customer;

    function get_customer_team_id(): int|false {
        return $this->_get_current_team_id();
    }

    function insert(array $data): int|false {
        return $this->db->insert($data, 'server');
    }

    function find_project(int $project_id): ?object {
        $row = $this->db->get_where($project_id, 'project');
        return $row ?: null;
    }

    function get_team_project(int $team_id): ?object {
        $rows = $this->db->query_bind(
            'SELECT * FROM project WHERE team_id = :team_id ORDER BY created_at DESC LIMIT 1',
            ['team_id' => $team_id],
            'object'
        );
        return $rows[0] ?? null;
    }

    function create_deployment(array $data): int|false {
        return $this->db->insert($data, 'deployment');
    }

    function queue_task(int $server_id, string $script, array $payload, ?int $deployment_id = null): int|false {
        return $this->db->insert([
            'server_id' => $server_id,
            'deployment_id' => $deployment_id,
            'script' => $script,
            'payload' => json_encode($payload)
        ], 'server_tasks');
    }

    function find_server(int $server_id): ?object {
        $row = $this->db->get_where($server_id, 'server');
        return $row ?: null;
    }

    function update_server(int $server_id, array $data): int|false {
        return $this->db->update($server_id, $data, 'server');
    }

    function get_server_deployments(int $server_id): array {
        $sql = '
            SELECT 
                d.*,
                p.label as project_label,
                p.type as project_type
            FROM deployment d
            LEFT JOIN project p ON d.project_id = p.id
            WHERE d.server_id = :server_id 
            ORDER BY d.created_at DESC
        ';
        return $this->db->query_bind($sql, ['server_id' => $server_id], 'object');
    }

    function get_server_projects(int $server_id): array {
        $sql = '
            SELECT DISTINCT
                p.*
            FROM deployment d
            JOIN project p ON d.project_id = p.id
            WHERE d.server_id = :server_id
            ORDER BY p.label ASC
        ';
        return $this->db->query_bind($sql, ['server_id' => $server_id], 'object');
    }

    function get_team_projects(int $team_id): array {
        $sql = 'SELECT * FROM project WHERE team_id = :team_id ORDER BY label ASC';
        return $this->db->query_bind($sql, ['team_id' => $team_id], 'object');
    }
}
