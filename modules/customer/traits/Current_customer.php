<?php

/**
 * Current customer resolution for models.
 * There is no "current project": controllers authorize each request against
 * the project or app it names (Platform_actions::find_member_project/find_member_app).
 *
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Db $db
 */
trait Current_customer {

    /**
     * Get the current customer from the auth token.
     * Not cached in session, so logout/login and membership changes take effect immediately.
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
}
