<?php
/**
 * App_runtime_model - Handles data operations for runtime records.
 *
 * Demonstrates proper data conversion patterns and separation
 * of concerns between database operations and presentation logic.
 */
class App_runtime_model extends Model {
    
    private string $table_name = 'app_runtime';

    /**
     * Return the list of searchable column names (used by the controller for validation).
     *
     * @return array<string>
     */
    public function get_searchable_columns(): array {
        return ['php_extensions', 'build_commands'];
    }





    /**
     * Fetch paginated runtimes records.
     *
     * @param int $limit Records per page
     * @param int $offset Records to skip
     * @return array<object>
     */
    public function fetch_records(int $limit, int $offset): array {
        $sql = 'SELECT * FROM '.$this->table_name.' ORDER BY app_id LIMIT '.$limit.' OFFSET '.$offset;
        return $this->db->query($sql, 'object');
    }
    
    /**
     * Count all runtimes records in the database table.
     *
     * @return int Total number of runtimes records.
     */
    public function count_all(): int {
        return $this->db->count($this->table_name);
    }

    /**
     * Search runtimes records across searchable columns.
     *
     * @param string $query The search query string.
     * @param string $column Specific column to search, or empty string to search all.
     * @param int $limit Maximum records to return.
     * @param int $offset Records to skip.
     * @return array<object>
     */
    public function search_records(string $query, string $column, int $limit, int $offset): array {
        $searchable_columns = $this->get_searchable_columns();

        // Validate column against whitelist before interpolating into SQL.
        if ($column !== '' && !in_array($column, $searchable_columns, true)) {
            $column = '';
        }

        if ($column !== '') {
            $sql = 'SELECT * FROM '.$this->table_name.' WHERE '.$column.' LIKE :query ORDER BY app_id LIMIT '.$limit.' OFFSET '.$offset;
        } else {
            $conditions = [];
            foreach ($searchable_columns as $col) {
                $conditions[] = $col.' LIKE :query';
            }
            $sql = 'SELECT * FROM '.$this->table_name.' WHERE ('.implode(' OR ', $conditions).') ORDER BY app_id LIMIT '.$limit.' OFFSET '.$offset;
        }

        return $this->db->query_bind($sql, ['query' => '%'.$query.'%'], 'object');
    }

    /**
     * Count search results for runtimes records.
     *
     * @param string $query The search query string.
     * @param string $column Specific column to search, or empty string to search all.
     * @return int Number of matching records.
     */
    public function count_search_results(string $query, string $column): int {
        $searchable_columns = $this->get_searchable_columns();

        // Validate column against whitelist before interpolating into SQL.
        if ($column !== '' && !in_array($column, $searchable_columns, true)) {
            $column = '';
        }

        if ($column !== '') {
            $sql = 'SELECT COUNT(*) as total FROM '.$this->table_name.' WHERE '.$column.' LIKE :query';
        } else {
            $conditions = [];
            foreach ($searchable_columns as $col) {
                $conditions[] = $col.' LIKE :query';
            }
            $sql = 'SELECT COUNT(*) as total FROM '.$this->table_name.' WHERE ('.implode(' OR ', $conditions).')';
        }

        $result = $this->db->query_bind($sql, ['query' => '%'.$query.'%'], 'object');
        return (int) ($result[0]->total ?? 0);
    }

    /**
     * Prepare multiple runtimes records for display in list views.
     *
     * @param array $rows Array of runtime record objects from database
     * @return array Array of objects with formatted display fields
     */
    public function prepare_records_for_display(array $rows): array {
        $prepared = [];
        foreach ($rows as $row) {
            $prepared[] = $this->prepare_record_for_display($row);
        }
        return $prepared;
    }

    /**
     * Prepare raw runtime database data for display in views.
     *
     * @param object $record_obj Raw data from database
     * @return object Enhanced data with formatted fields
     */
    public function prepare_record_for_display(object $record_obj): object {
        $record_obj->php_extensions = trim((string) $record_obj->php_extensions);
        $record_obj->build_commands = trim((string) $record_obj->build_commands);
        $record_obj->persistent_paths = trim((string) $record_obj->persistent_paths);
        $record_obj->always_on = ($record_obj->always_on == 1) ? 'yes' : 'no';
        $record_obj->trongate_env = trim((string) $record_obj->trongate_env);
        $record_obj->mail_capture = ($record_obj->mail_capture == 1) ? 'yes' : 'no';
        $record_obj->health_path = trim((string) $record_obj->health_path);
        $record_obj->updated_at = trim((string) $record_obj->updated_at);
        return $record_obj;
    }



}
