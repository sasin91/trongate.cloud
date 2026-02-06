<?php

require_once __DIR__ . '/../customer/traits/Current_customer.php';

/**
 * @property-read Db $db
 */
class Projects_model extends Model {
    use Current_customer;
    
    public string $table_name = 'project';

    public function find(int $id): object|bool {
        return $this->db->get_where($id, $this->table_name);
    }

    public function get_team_servers(int $team_id): array {
        return $this->db->query_bind(
            <<<SQL
                SELECT *
                FROM server
                WHERE team_id = :team_id
            SQL,
            ['team_id' => $team_id],
            'object'
        );
    }
}
