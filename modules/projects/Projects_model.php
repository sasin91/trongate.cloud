<?php
class Projects_model extends Model {
    
    public string $table_name = 'project';

    public function get_where(int $id): object|bool {
        $sql = "SELECT * FROM {$this->table_name} WHERE id = :id";
        $data['id'] = $id;
        $result = $this->db->query_bind($sql, $data, 'object');
        return $result[0] ?? false;
    }
}
