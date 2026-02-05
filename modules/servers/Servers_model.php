<?php
require_once APPPATH . 'modules/customer/traits/Current_customer.php';

class Servers_model extends Model {
    use Current_customer;

    function get_customer_team_id(): int|false {
        return $this->_get_current_team_id();
    }

    function insert(array $data): int|false {
        return $this->db->insert($data, 'server');
    }

    function find_project($project_id): ?object {
        if (empty($project_id)) {
            return null;
        }

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
}
