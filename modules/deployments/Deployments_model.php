<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';

class Deployments_model extends Model {
    use Current_customer;

    function get_customer_team_id(): int|false {
        return $this->_get_current_team_id();
    }

    function find(int $id): object|false {
        return $this->db->get_where($id, 'deployment');
    }

    function get_logs_since(int $server_id, int $last_log_id): array {
        return $this->db->query_bind(
            <<<SQL
                SELECT id, level, message, created_at
                FROM server_logs
                WHERE server_id = :server_id
                AND id > :last_log_id
                ORDER BY id ASC
                LIMIT 100
            SQL,
            ['server_id' => $server_id, 'last_log_id' => $last_log_id],
            'object'
        );
    }

    function get_task(int $deployment_id): ?object {
        $rows = $this->db->query_bind(
            'SELECT * FROM server_tasks WHERE deployment_id = :deployment_id LIMIT 1',
            ['deployment_id' => $deployment_id],
            'object'
        );
        return $rows[0] ?? null;
    }

    function get_project(int $project_id): ?object {
        return $this->db->get_where($project_id, 'project');
    }

    function update_status(int $deployment_id, string $status): bool {
        $data = ['status' => $status];
        if (in_array($status, ['success', 'failed'])) {
            $data['completed_at'] = date('Y-m-d H:i:s');
        }
        return $this->db->update($deployment_id, $data, 'deployment');
    }
}
