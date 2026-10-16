<?php
/**
 * App_model - Handles data operations for app records.
 *
 * Demonstrates proper data conversion patterns and separation
 * of concerns between database operations and presentation logic.
 */
class App_model extends Model {
    
    private string $table_name = 'app';

    // Never php_extensions, build_commands, setup_sql
    private string $columns = '`id`, `project_id`, `label`, `slug`, `serves_slug_host`, `type`, `environment`, `production_app_id`, `domain`, `verified_domain`, `domain_pointed_at`, `domain_secured_at`, `domain_dns_failures`, `domain_checked_at`, `source_url`, `source_branch`, `source_provider`, `auto_deploy`, `reconciled_at`, `persistent_paths`, `always_on`, `trongate_env`, `mail_capture`, `health_path`, `setup_sql_status`, `setup_sql_error`, `database_server_id`, `current_deployment_id`, `created_at`, `deleted_at`';

    /**
     * Return the list of searchable column names (used by the controller for validation).
     *
     * @return array<string>
     */
    public function get_searchable_columns(): array {
        return ['label', 'slug', 'domain', 'source_url'];
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
     * Fetch paginated apps records.
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
     * Count all apps records in the database table.
     *
     * @return int Total number of apps records.
     */
    public function count_all(): int {
        return $this->db->count($this->table_name);
    }

    /**
     * Search apps records across searchable columns.
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
     * Count search results for apps records.
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
     * Prepare multiple apps records for display in list views.
     *
     * @param array $rows Array of app record objects from database
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
     * Prepare raw app database data for display in views.
     *
     * @param object $record_obj Raw data from database
     * @return object Enhanced data with formatted fields
     */
    public function prepare_record_for_display(object $record_obj): object {
        $record_obj->label = trim((string) $record_obj->label);
        $record_obj->slug = trim((string) $record_obj->slug);
        $record_obj->serves_slug_host = ($record_obj->serves_slug_host == 1) ? 'yes' : 'no';
        $record_obj->type = trim((string) $record_obj->type);
        $record_obj->environment = trim((string) $record_obj->environment);
        $record_obj->domain = trim((string) $record_obj->domain);
        $record_obj->verified_domain = trim((string) $record_obj->verified_domain);
        $record_obj->domain_pointed_at = trim((string) $record_obj->domain_pointed_at);
        $record_obj->domain_secured_at = trim((string) $record_obj->domain_secured_at);
        $record_obj->domain_checked_at = trim((string) $record_obj->domain_checked_at);
        $record_obj->source_url = trim((string) $record_obj->source_url);
        $record_obj->source_branch = trim((string) $record_obj->source_branch);
        $record_obj->source_provider = trim((string) $record_obj->source_provider);
        $record_obj->auto_deploy = ($record_obj->auto_deploy == 1) ? 'yes' : 'no';
        $record_obj->reconciled_at = trim((string) $record_obj->reconciled_at);
        $record_obj->persistent_paths = trim((string) $record_obj->persistent_paths);
        $record_obj->always_on = ($record_obj->always_on == 1) ? 'yes' : 'no';
        $record_obj->trongate_env = trim((string) $record_obj->trongate_env);
        $record_obj->mail_capture = ($record_obj->mail_capture == 1) ? 'yes' : 'no';
        $record_obj->health_path = trim((string) $record_obj->health_path);
        $record_obj->setup_sql_status = trim((string) $record_obj->setup_sql_status);
        $record_obj->setup_sql_error = trim((string) $record_obj->setup_sql_error);
        $record_obj->created_at = trim((string) $record_obj->created_at);
        $record_obj->deleted_at = trim((string) $record_obj->deleted_at);
        return $record_obj;
    }



}
