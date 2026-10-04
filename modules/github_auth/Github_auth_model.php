<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';

/**
 * Customers by their linked GitHub account (customer.github_user_id).
 *
 * @property-read Db $db
 * @property-read Trongate_tokens $trongate_tokens
 */
class Github_auth_model extends Model {
    use Current_customer;

    function find_by_github_id(int $github_user_id): object|false {
        $sql = <<<SQL
            SELECT * FROM customer
            WHERE github_user_id = :github_user_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['github_user_id' => $github_user_id], 'object');

        return $rows[0] ?? false;
    }

    function email_exists(string $email): bool {
        $sql = <<<SQL
            SELECT 1 FROM customer
            WHERE email = :email
            LIMIT 1
        SQL;

        return (bool) $this->db->query_bind($sql, ['email' => $email], 'column');
    }

    /**
     * Link (or refresh the login of) a customer's GitHub account
     */
    function link_github(int $customer_id, int $github_user_id, string $github_login): bool {
        return $this->db->update($customer_id, [
            'github_user_id' => $github_user_id,
            'github_login' => $github_login,
        ], 'customer');
    }

    /**
     * A customer who signed up with GitHub: no password, so only GitHub signs
     * them in (the login module refuses an empty password column).
     */
    function create_github_account(string $email, int $github_user_id, string $github_login): int|false {
        return $this->db->transaction(function () use ($email, $github_user_id, $github_login) {
            $trongate_user_id = $this->db->insert(['code' => make_rand_str(32), 'user_level_id' => 2], 'trongate_users');

            if (!$trongate_user_id) {
                return false;
            }

            return $this->db->insert([
                'trongate_user_id' => $trongate_user_id,
                'email' => $email,
                'github_user_id' => $github_user_id,
                'github_login' => $github_login,
                'active' => 1,
            ], 'customer');
        });
    }

    function find_customer(int $customer_id): object|false {
        $rows = $this->db->query_bind('SELECT * FROM customer WHERE id = :id LIMIT 1', ['id' => $customer_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * Sign a customer in like a remembered password login (config/login.php,
     * user level 2): a token in the trongatetoken cookie.
     */
    function log_in(object $customer, int $remember_days): void {
        $this->module('trongate_tokens');
        $this->trongate_tokens->destroy();
        $this->trongate_tokens->generate_token([
            'user_id' => (int) $customer->trongate_user_id,
            'expiry_date' => time() + 86400 * $remember_days,
            'set_cookie' => true,
        ]);
    }
}
