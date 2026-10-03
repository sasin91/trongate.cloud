<?php

/**
 * Current customer & team resolution for models.
 * Team membership is resolved via team_member only (owners are always members).
 *
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Db $db
 */
trait Current_customer {

    /**
     * Get the current customer from the auth token.
     * Not cached in session, so logout/login and team removal take effect immediately.
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
     * Get the current customer's selected team ID.
     * Uses customer.current_team_id while still a member, else the first team (auto-set).
     *
     * @return int|false The team ID or false if not found
     */
    function _get_current_team_id(): int|false {
        $customer = $this->_get_current_customer();
        if ($customer === false) {
            return false;
        }

        $teams = $this->get_customer_teams();
        $team_ids = array_map(fn(object $team) => (int) $team->id, $teams);

        if (in_array((int) $customer->current_team_id, $team_ids, true)) {
            return (int) $customer->current_team_id;
        }

        if (empty($team_ids)) {
            return false;
        }

        $this->set_current_team($team_ids[0]);
        return $team_ids[0];
    }

    /**
     * Get all teams for the current customer
     */
    function get_customer_teams(): array {
        $customer = $this->_get_current_customer();
        if ($customer === false) {
            return [];
        }

        $sql = <<<SQL
            SELECT t.id, t.name FROM team t
            INNER JOIN team_member tm ON t.id = tm.team_id
            WHERE tm.customer_id = :customer_id
            ORDER BY t.name ASC
        SQL;
        return $this->db->query_bind($sql, ['customer_id' => $customer->id], 'object');
    }

    /**
     * Set the current team for the customer (must be a member)
     */
    function set_current_team(int $team_id): bool {
        $customer = $this->_get_current_customer();
        if ($customer === false) {
            return false;
        }

        $sql = <<<SQL
            SELECT 1 FROM team_member
            WHERE team_id = :team_id AND customer_id = :customer_id
            LIMIT 1
        SQL;
        $is_member = $this->db->query_bind($sql, ['team_id' => $team_id, 'customer_id' => $customer->id], 'column');
        if (empty($is_member)) {
            return false;
        }

        return (bool) $this->db->update((int) $customer->id, ['current_team_id' => $team_id], 'customer');
    }

    /**
     * Get the current customer's team member record
     */
    function get_current_team_member(): object|false {
        $customer = $this->_get_current_customer();
        if ($customer === false) {
            return false;
        }

        $team_id = $this->_get_current_team_id();
        if ($team_id === false) {
            return false;
        }

        $sql = <<<SQL
            SELECT * FROM team_member
            WHERE team_id = :team_id
            AND customer_id = :customer_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['team_id' => $team_id, 'customer_id' => $customer->id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * Find a row by ID, only if it belongs to the current team
     * @param string $table Table name (must have a team_id column)
     * @param int $id
     * @return object|false
     */
    function _find_for_team(string $table, int $id): object|false {
        if (!preg_match('/^[a-z_]+$/', $table)) {
            return false;
        }

        $team_id = $this->_get_current_team_id();
        if ($team_id === false) {
            return false;
        }

        $row = $this->db->get_where($id, $table);
        if (!is_object($row) || (int) $row->team_id !== $team_id) {
            return false;
        }

        return $row;
    }
}
