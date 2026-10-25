<?php
/**
 * Daily_backups_model - Handles data operations for nightly backups records.
 *
 * Demonstrates proper data conversion patterns and separation
 * of concerns between database operations and presentation logic.
 */
class Daily_backups_model extends Model {
    
    private string $table_name = 'daily_backup';

    // Never object_prefix (where the backup sits in the bucket)
    private string $columns = '`id`, `source`, `app_id`, `status`, `bytes`, `error`, `created_at`, `ready_at`, `expires_at`';

    /**
     * Return the list of searchable column names (used by the controller for validation).
     *
     * @return array<string>
     */
    public function get_searchable_columns(): array {
        return ['error'];
    }





    /**
     * Fetch paginated nightly backups records.
     *
     * @param int $limit Records per page
     * @param int $offset Records to skip
     * @return array<object>
     */
    public function fetch_records(int $limit, int $offset): array {
        $sql = 'SELECT '.$this->columns.' FROM '.$this->table_name.' ORDER BY created_at DESC LIMIT '.$limit.' OFFSET '.$offset;
        return $this->db->query($sql, 'object');
    }
    
    /**
     * Count all nightly backups records in the database table.
     *
     * @return int Total number of nightly backups records.
     */
    public function count_all(): int {
        return $this->db->count($this->table_name);
    }

    /**
     * Search nightly backups records across searchable columns.
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
            $sql = 'SELECT '.$this->columns.' FROM '.$this->table_name.' WHERE '.$column.' LIKE :query ORDER BY created_at DESC LIMIT '.$limit.' OFFSET '.$offset;
        } else {
            $conditions = [];
            foreach ($searchable_columns as $col) {
                $conditions[] = $col.' LIKE :query';
            }
            $sql = 'SELECT '.$this->columns.' FROM '.$this->table_name.' WHERE ('.implode(' OR ', $conditions).') ORDER BY created_at DESC LIMIT '.$limit.' OFFSET '.$offset;
        }

        return $this->db->query_bind($sql, ['query' => '%'.$query.'%'], 'object');
    }

    /**
     * Count search results for nightly backups records.
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
     * Prepare multiple nightly backups records for display in list views.
     *
     * @param array $rows Array of nightly backups record objects from database
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
     * Prepare raw nightly backups database data for display in views.
     *
     * @param object $record_obj Raw data from database
     * @return object Enhanced data with formatted fields
     */
    public function prepare_record_for_display(object $record_obj): object {
        $record_obj->status = trim((string) $record_obj->status);
        $record_obj->error = trim((string) $record_obj->error);
        $record_obj->bytes = trim((string) $record_obj->bytes);
        $record_obj->created_at = trim((string) $record_obj->created_at);
        $record_obj->ready_at = trim((string) $record_obj->ready_at);
        $record_obj->expires_at = trim((string) $record_obj->expires_at);
        return $record_obj;
    }



}
