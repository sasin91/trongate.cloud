<?php
/**
 * Schema_migrations_model - Handles data operations for migration records.
 *
 * Demonstrates proper data conversion patterns and separation
 * of concerns between database operations and presentation logic.
 */
class Schema_migrations_model extends Model {
    
    private string $table_name = 'schema_migrations';

    /**
     * Return the list of searchable column names (used by the controller for validation).
     *
     * @return array<string>
     */
    public function get_searchable_columns(): array {
        return [];
    }





    /**
     * Fetch paginated migrations records.
     *
     * @param int $limit Records per page
     * @param int $offset Records to skip
     * @return array<object>
     */
    public function fetch_records(int $limit, int $offset): array {
        $sql = 'SELECT * FROM '.$this->table_name.' ORDER BY name desc LIMIT '.$limit.' OFFSET '.$offset;
        return $this->db->query($sql, 'object');
    }
    
    /**
     * Count all migrations records in the database table.
     *
     * @return int Total number of migrations records.
     */
    public function count_all(): int {
        return $this->db->count($this->table_name);
    }

    /**
     * Prepare multiple migrations records for display in list views.
     *
     * @param array $rows Array of migration record objects from database
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
     * Prepare raw migration database data for display in views.
     *
     * @param object $record_obj Raw data from database
     * @return object Enhanced data with formatted fields
     */
    public function prepare_record_for_display(object $record_obj): object {
        $record_obj->name = trim((string) $record_obj->name);
        $record_obj->applied_at = trim((string) $record_obj->applied_at);
        return $record_obj;
    }



}
