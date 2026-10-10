<?php
/**
 * Scheduled_jobs_model - Handles data operations for scheduled job records.
 *
 * Demonstrates proper data conversion patterns and separation
 * of concerns between database operations and presentation logic.
 */
class Scheduled_jobs_model extends Model {
    
    private string $table_name = 'scheduled_jobs';

    /**
     * Return the list of searchable column names (used by the controller for validation).
     *
     * @return array<string>
     */
    public function get_searchable_columns(): array {
        return [];
    }





    /**
     * Fetch paginated scheduled jobs records.
     *
     * @param int $limit Records per page
     * @param int $offset Records to skip
     * @return array<object>
     */
    public function fetch_records(int $limit, int $offset): array {
        $sql = 'SELECT * FROM '.$this->table_name.' ORDER BY script LIMIT '.$limit.' OFFSET '.$offset;
        return $this->db->query($sql, 'object');
    }
    
    /**
     * Count all scheduled jobs records in the database table.
     *
     * @return int Total number of scheduled jobs records.
     */
    public function count_all(): int {
        return $this->db->count($this->table_name);
    }

    /**
     * Prepare multiple scheduled jobs records for display in list views.
     *
     * @param array $rows Array of scheduled job record objects from database
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
     * Prepare raw scheduled job database data for display in views.
     *
     * @param object $record_obj Raw data from database
     * @return object Enhanced data with formatted fields
     */
    public function prepare_record_for_display(object $record_obj): object {
        $record_obj->script = trim((string) $record_obj->script);
        $record_obj->last_queued_at = trim((string) $record_obj->last_queued_at);
        $record_obj->last_finished_at = trim((string) $record_obj->last_finished_at);
        $record_obj->last_failed_at = trim((string) $record_obj->last_failed_at);
        $record_obj->last_summary = trim((string) $record_obj->last_summary);
        return $record_obj;
    }



}
