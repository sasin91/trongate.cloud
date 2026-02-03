<?php

/**
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Db $db
 */
trait Current_customer {

    /**
     * Get the current customer based on session ID or token.
     * Only the ID is cached in session to avoid stale data.
     * 
     * @return object|false The customer object or false if not found/logged in
     */
    function _get_current_customer(): object|false {
        $customer_id = $_SESSION['customer_id'] ?? null;

        // If not in session, look up from token
        if ($customer_id === null) {
            $this->module('trongate_tokens');
            $trongate_user_id = $this->trongate_tokens->get_user_id();

            if ($trongate_user_id === false) {
                return false;
            }

            $rows = $this->db->query_bind(
                "SELECT id FROM customer WHERE active = 1 AND trongate_user_id = :trongate_user_id LIMIT 1",
                ['trongate_user_id' => $trongate_user_id],
                'object'
            );

            if (empty($rows)) {
                return false;
            }

            $customer_id = (int) $rows[0]->id;
            $_SESSION['customer_id'] = $customer_id;
        }

        // Always fetch fresh data
        $rows = $this->db->query_bind(
            "SELECT * FROM customer WHERE id = :id AND active = 1 LIMIT 1",
            ['id' => $customer_id],
            'object'
        );

        return $rows[0] ?? false;
    }

    /**
     * Get the current customer's selected team ID.
     * Falls back to first team if not set.
     * 
     * @return int|false The team ID or false if not found
     */
    function _get_current_team_id(): int|false {
        $customer = $this->_get_current_customer();
        if ($customer === false) return false;

        // If set, return it
        if (!empty($customer->current_team_id)) {
            return (int) $customer->current_team_id;
        }

        // Fallback: first team via membership or ownership
        $sql = <<<SQL
            SELECT t.id FROM team t
            LEFT JOIN team_member tm ON t.id = tm.team_id
            WHERE t.owner_id = :customer_id OR tm.customer_id = :customer_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['customer_id' => $customer->id], 'object');
        
        return !empty($rows) ? (int) $rows[0]->id : false;
    }
}
