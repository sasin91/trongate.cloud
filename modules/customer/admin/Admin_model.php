<?php
/**
 * Admin_model (customer) - Handles data operations for customer records.
 *
 * Demonstrates proper data conversion patterns and separation
 * of concerns between database operations and presentation logic.
 */
class Admin_model extends Model {
    
    private string $table_name = 'customer';

    // Never password
    private string $columns = '`id`, `trongate_user_id`, `email`, `github_user_id`, `github_login`, `active`, `onboarded_at`, `created_at`';

    /**
     * Return the list of searchable column names (used by the controller for validation).
     *
     * @return array<string>
     */
    public function get_searchable_columns(): array {
        return ['email', 'github_login'];
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
     * Fetch paginated customers records.
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
     * Count all customers records in the database table.
     *
     * @return int Total number of customers records.
     */
    public function count_all(): int {
        return $this->db->count($this->table_name);
    }

    /**
     * Search customers records across searchable columns.
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
     * Count search results for customers records.
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
     * Prepare multiple customers records for display in list views.
     *
     * @param array $rows Array of customer record objects from database
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
     * Prepare raw customer database data for display in views.
     *
     * @param object $record_obj Raw data from database
     * @return object Enhanced data with formatted fields
     */
    public function prepare_record_for_display(object $record_obj): object {
        $record_obj->email = trim((string) $record_obj->email);
        $record_obj->github_login = trim((string) $record_obj->github_login);
        $record_obj->active = ($record_obj->active == 1) ? 'yes' : 'no';
        $record_obj->onboarded_at = trim((string) $record_obj->onboarded_at);
        $record_obj->created_at = trim((string) $record_obj->created_at);
        return $record_obj;
    }

    /**
     * Build the related records panels shown below a customer's record.
     *
     * @param int $customer_id Customer ID.
     * @return array<array<string, mixed>> Panels for related_records/draw_panel
     */
    public function related_panels(int $customer_id): array {
        $projects = $this->fetch_projects($customer_id);

        $project_ids = [];
        foreach ($projects as $project) {
            $project_ids[] = (int) $project->id;
        }
        $apps = $this->fetch_apps($project_ids);

        return [
            $this->projects_panel($projects),
            $this->apps_panel($apps),
        ];
    }

    /**
     * Fetch the projects a customer owns or is a member of, deleted ones included.
     *
     * @param int $customer_id Customer ID.
     * @return array<object>
     */
    private function fetch_projects(int $customer_id): array {
        $sql = 'SELECT project.id, project.name, project.namespace,
                    COALESCE(project_member.role, \'owner\') AS role,
                    project.created_at, project.deleted_at
                FROM project
                LEFT JOIN project_member
                    ON project_member.project_id = project.id
                    AND project_member.customer_id = :member_id
                WHERE project.owner_id = :owner_id OR project_member.id IS NOT NULL
                ORDER BY project.id';
        $params = ['member_id' => $customer_id, 'owner_id' => $customer_id];
        return $this->db->query_bind($sql, $params, 'object');
    }

    /**
     * Fetch the apps in the given projects, deleted ones included.
     *
     * @param array<int> $project_ids Project IDs.
     * @return array<object>
     */
    private function fetch_apps(array $project_ids): array {
        if (count($project_ids) === 0) {
            return [];
        }

        $sql = 'SELECT app.id, app.label, app.slug, app.environment,
                    project.name AS project_name, app.created_at, app.deleted_at
                FROM app
                JOIN project ON project.id = app.project_id
                WHERE app.project_id IN ('.implode(', ', $project_ids).')
                ORDER BY app.project_id, app.id';
        return $this->db->query($sql, 'object');
    }

    /**
     * @param array<object> $projects From fetch_projects().
     * @return array<string, mixed>
     */
    private function projects_panel(array $projects): array {
        $rows = [];
        foreach ($projects as $project) {
            $rows[] = [
                'url' => BASE_URL.'project/show/'.$project->id,
                'link_text' => $project->name,
                'cells' => [
                    $project->namespace,
                    $project->role,
                    (string) $project->created_at,
                    (string) $project->deleted_at,
                ],
            ];
        }

        return [
            'heading' => 'Projects',
            'columns' => ['Name', 'Namespace', 'Role', 'Created At', 'Deleted At'],
            'rows' => $rows,
            'empty_message' => 'This customer owns no projects and is a member of none.',
        ];
    }

    /**
     * @param array<object> $apps From fetch_apps().
     * @return array<string, mixed>
     */
    private function apps_panel(array $apps): array {
        $rows = [];
        foreach ($apps as $app) {
            $rows[] = [
                'url' => BASE_URL.'app/show/'.$app->id,
                'link_text' => $app->label,
                'cells' => [
                    $app->slug,
                    $app->environment,
                    $app->project_name,
                    (string) $app->created_at,
                    (string) $app->deleted_at,
                ],
            ];
        }

        return [
            'heading' => 'Apps',
            'columns' => ['Label', 'Slug', 'Environment', 'Project', 'Created At', 'Deleted At'],
            'rows' => $rows,
            'empty_message' => 'None of this customer\'s projects has an app.',
        ];
    }

}
