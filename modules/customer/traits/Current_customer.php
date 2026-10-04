<?php

/**
 * Current customer & project resolution for models.
 * Project membership is resolved via project_member only (owners are always members).
 *
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Db $db
 */
trait Current_customer {

    /**
     * Get the current customer from the auth token.
     * Not cached in session, so logout/login and project removal take effect immediately.
     *
     * @return object|false The customer object or false if not found/logged in
     */
    function _get_current_customer(): object|false {
        $this->module('trongate_tokens');
        $trongate_user_id = $this->trongate_tokens->get_user_id();

        if ($trongate_user_id === false) {
            return false;
        }

        $sql = <<<SQL
            SELECT * FROM customer
            WHERE trongate_user_id = :trongate_user_id AND active = 1
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['trongate_user_id' => $trongate_user_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * Get the current customer's selected project ID.
     * Uses customer.current_project_id while still a member, else the first project (auto-set).
     *
     * @return int|false The project ID or false if not found
     */
    function _get_current_project_id(): int|false {
        $customer = $this->_get_current_customer();
        if ($customer === false) {
            return false;
        }

        $projects = $this->get_customer_projects();
        $project_ids = array_map(fn(object $project) => (int) $project->id, $projects);

        if (in_array((int) $customer->current_project_id, $project_ids, true)) {
            return (int) $customer->current_project_id;
        }

        if (empty($project_ids)) {
            return false;
        }

        $this->set_current_project($project_ids[0]);
        return $project_ids[0];
    }

    /**
     * Get all projects for the current customer
     */
    function get_customer_projects(): array {
        $customer = $this->_get_current_customer();
        if ($customer === false) {
            return [];
        }

        $sql = <<<SQL
            SELECT t.id, t.name FROM project t
            INNER JOIN project_member tm ON t.id = tm.project_id
            WHERE tm.customer_id = :customer_id
            ORDER BY t.name ASC
        SQL;
        return $this->db->query_bind($sql, ['customer_id' => $customer->id], 'object');
    }

    /**
     * Set the current project for the customer (must be a member)
     */
    function set_current_project(int $project_id): bool {
        $customer = $this->_get_current_customer();
        if ($customer === false) {
            return false;
        }

        $sql = <<<SQL
            SELECT 1 FROM project_member
            WHERE project_id = :project_id AND customer_id = :customer_id
            LIMIT 1
        SQL;
        $is_member = $this->db->query_bind($sql, ['project_id' => $project_id, 'customer_id' => $customer->id], 'column');
        if (empty($is_member)) {
            return false;
        }

        return (bool) $this->db->update((int) $customer->id, ['current_project_id' => $project_id], 'customer');
    }

    /**
     * Get the current customer's project member record
     */
    function get_current_project_member(): object|false {
        $customer = $this->_get_current_customer();
        if ($customer === false) {
            return false;
        }

        $project_id = $this->_get_current_project_id();
        if ($project_id === false) {
            return false;
        }

        $sql = <<<SQL
            SELECT * FROM project_member
            WHERE project_id = :project_id
            AND customer_id = :customer_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['project_id' => $project_id, 'customer_id' => $customer->id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * Find a row by ID, only if it belongs to the current project
     * @param string $table Table name (must have a project_id column)
     * @param int $id
     * @return object|false
     */
    function _find_for_project(string $table, int $id): object|false {
        if (!preg_match('/^[a-z_]+$/', $table)) {
            return false;
        }

        $project_id = $this->_get_current_project_id();
        if ($project_id === false) {
            return false;
        }

        $row = $this->db->get_where($id, $table);
        if (!is_object($row) || (int) $row->project_id !== $project_id) {
            return false;
        }

        return $row;
    }
}
