<?php

/**
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Db $db
 */
trait Current_customer
{
    /**
     * Get the current customer ID based on the active token.
     * 
     * @return object|false The customer object or false if not found/logged in
     */
    public function _get_current_customer(): object|false
    {
        $this->module('trongate_tokens');
        $trongate_user_id = $this->trongate_tokens->get_user_id();

        if ($trongate_user_id === false) {
            return false;
        }

        $rows = $this->db->query_bind(
            <<<SQL
                SELECT * FROM customer
                WHERE active = 1
                AND trongate_user_id = :trongate_user_id
                LIMIT 1
            SQL,
            ['trongate_user_id' => $trongate_user_id],
            'object'
        );

        return $rows[0] ?? false;
    }
}
