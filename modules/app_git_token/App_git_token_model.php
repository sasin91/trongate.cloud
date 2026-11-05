<?php
/**
 * App_git_token_model - Handles data operations for git access token records.
 *
 * Demonstrates proper data conversion patterns and separation
 * of concerns between database operations and presentation logic.
 */
class App_git_token_model extends Model {
    
    private string $table_name = 'app_git_token';

    // Never token_hash
    private string $columns = '`id`, `app_id`, `customer_id`, `label`, `last_four`, `created_at`, `last_used_at`, `revoked_at`';

    /**
     * Return the list of searchable column names (used by the controller for validation).
     *
     * @return array<string>
     */
    public function get_searchable_columns(): array {
        return [];
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
     * Fetch paginated git access tokens records.
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
     * Count all git access tokens records in the database table.
     *
     * @return int Total number of git access tokens records.
     */
    public function count_all(): int {
        return $this->db->count($this->table_name);
    }

    /**
     * Prepare multiple git access tokens records for display in list views.
     *
     * @param array $rows Array of git access token record objects from database
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
     * Prepare raw git access token database data for display in views.
     *
     * @param object $record_obj Raw data from database
     * @return object Enhanced data with formatted fields
     */
    public function prepare_record_for_display(object $record_obj): object {
        $record_obj->label = trim((string) $record_obj->label);
        $record_obj->last_four = trim((string) $record_obj->last_four);
        $record_obj->created_at = trim((string) $record_obj->created_at);
        $record_obj->last_used_at = trim((string) $record_obj->last_used_at);
        $record_obj->revoked_at = trim((string) $record_obj->revoked_at);
        return $record_obj;
    }



}
