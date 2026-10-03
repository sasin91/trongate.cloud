<?php

require_once __DIR__ . '/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';

/**
 * @property-read Db $db
 */
class Customer_model extends Model {
    use Current_customer;
    use Platform_actions;

    private string $table_name = 'customer';

    function email_exists(string $email): bool {
        $sql = <<<SQL
            SELECT 1 FROM {$this->table_name}
            WHERE email = :email
            LIMIT 1
        SQL;
        $params = ['email' => $email];
        return (bool) $this->db->query_bind($sql, $params, 'column');
    }

    /**
     * Create a customer and its trongate_users record
     * @param string $password_hash Hashed via the login module
     */
    function create_account(string $email, string $password_hash): int|false {
        return $this->db->transaction(function () use ($email, $password_hash) {
            $trongate_user_id = $this->db->insert(['code' => make_rand_str(32), 'user_level_id' => 2], 'trongate_users');
            if (!$trongate_user_id) {
                return false;
            }
            return $this->db->insert([
                'trongate_user_id' => $trongate_user_id,
                'email' => $email,
                'password' => $password_hash,
                'active' => 1,
            ], $this->table_name);
        });
    }
}
