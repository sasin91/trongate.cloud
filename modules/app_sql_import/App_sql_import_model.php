<?php
/**
 * App_sql_import_model - Handles data operations for sql import records.
 *
 * Demonstrates proper data conversion patterns and separation
 * of concerns between database operations and presentation logic.
 */
class App_sql_import_model extends Model {
    
    private string $table_name = 'app_sql_import';

    // Never settings
    private string $columns = '`id`, `app_id`, `customer_id`, `task_id`, `log_id`, `file_name`, `bytes`, `compressed`, `status`, `sql_bytes`, `total_statements`, `summary`, `position`, `line`, `statement_number`, `statements_run`, `statements_skipped`, `error`, `can_continue`, `skip_first`, `file_deleted`, `created_at`, `updated_at`, `completed_at`';

    /**
     * Return the list of searchable column names (used by the controller for validation).
     *
     * @return array<string>
     */
    public function get_searchable_columns(): array {
        return ['file_name', 'error'];
    }


    /**
     * Retrieve a single record from the database by ID.
     *
     * @param int $update_id Record ID.
     * @param bool $prepare_for_display Whether to pass the record through prepare_record_for_display().
     * @return array|bool Associative array on success or false on failure.
     */
    public function get_data_from_db(int $update_id, bool $prepare_for_display = false): array|bool {
        $record_obj = $this->db->query_bind('SELECT '.$this->columns.' FROM '.$this->table_name.' WHERE id = :id', ['id' => $update_id], 'object')[0] ?? false;

        if ($record_obj === false) {
            return false;
        }

        if ($prepare_for_display === true) {
            $record_obj = $this->prepare_record_for_display($record_obj);
        }

        return (array) $record_obj;
    }



    /**
     * Fetch paginated sql imports records.
     *
     * @param int $limit Records per page
     * @param int $offset Records to skip
     * @return array<object>
     */
    public function fetch_records(int $limit, int $offset): array {
        $sql = 'SELECT '.$this->columns.' FROM '.$this->table_name.' ORDER BY id desc LIMIT '.$limit.' OFFSET '.$offset;
        return $this->db->query($sql, 'object');
    }
    
    /**
     * Count all sql imports records in the database table.
     *
     * @return int Total number of sql imports records.
     */
    public function count_all(): int {
        return $this->db->count($this->table_name);
    }

    /**
     * Search sql imports records across searchable columns.
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
            $sql = 'SELECT '.$this->columns.' FROM '.$this->table_name.' WHERE '.$column.' LIKE :query ORDER BY id desc LIMIT '.$limit.' OFFSET '.$offset;
        } else {
            $conditions = [];
            foreach ($searchable_columns as $col) {
                $conditions[] = $col.' LIKE :query';
            }
            $sql = 'SELECT '.$this->columns.' FROM '.$this->table_name.' WHERE ('.implode(' OR ', $conditions).') ORDER BY id desc LIMIT '.$limit.' OFFSET '.$offset;
        }

        return $this->db->query_bind($sql, ['query' => '%'.$query.'%'], 'object');
    }

    /**
     * Count search results for sql imports records.
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
     * Prepare multiple sql imports records for display in list views.
     *
     * @param array $rows Array of sql import record objects from database
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
     * Prepare raw sql import database data for display in views.
     *
     * @param object $record_obj Raw data from database
     * @return object Enhanced data with formatted fields
     */
    public function prepare_record_for_display(object $record_obj): object {
        $record_obj->file_name = trim((string) $record_obj->file_name);
        $record_obj->compressed = ($record_obj->compressed == 1) ? 'yes' : 'no';
        $record_obj->status = trim((string) $record_obj->status);
        $record_obj->summary = trim((string) $record_obj->summary);
        $record_obj->error = trim((string) $record_obj->error);
        $record_obj->can_continue = ($record_obj->can_continue == 1) ? 'yes' : 'no';
        $record_obj->skip_first = ($record_obj->skip_first == 1) ? 'yes' : 'no';
        $record_obj->file_deleted = ($record_obj->file_deleted == 1) ? 'yes' : 'no';
        $record_obj->created_at = trim((string) $record_obj->created_at);
        $record_obj->updated_at = trim((string) $record_obj->updated_at);
        $record_obj->completed_at = trim((string) $record_obj->completed_at);
        return $record_obj;
    }



}
