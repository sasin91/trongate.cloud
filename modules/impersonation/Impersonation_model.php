<?php

/**
 * The customer and admin rows an impersonation names, and the customer token
 * it signs in with (trongate_tokens, like a password or GitHub sign-in).
 */
class Impersonation_model extends Model {

    function find_customer(int $customer_id): object|false {
        $sql = <<<SQL
            SELECT id, trongate_user_id, email, github_login, active
            FROM customer
            WHERE id = :id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['id' => $customer_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * The username of the administrator signed in with this token
     */
    function admin_username(string $token): string {
        $sql = <<<SQL
            SELECT trongate_administrators.username
            FROM trongate_administrators
            INNER JOIN trongate_tokens ON trongate_tokens.user_id = trongate_administrators.trongate_user_id
            WHERE trongate_tokens.token = :token
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['token' => $token], 'object');

        return (string) ($rows[0]->username ?? 'unknown admin');
    }

    /**
     * A customer token valid for Impersonation_rules::MINUTES. Not set as a
     * cookie here: the controller decides where it goes.
     */
    function create_customer_token(int $trongate_user_id, int $expiry_date): string {
        $token = make_rand_str(32);

        $this->db->insert([
            'token' => $token,
            'user_id' => $trongate_user_id,
            'expiry_date' => $expiry_date,
            'code' => 'imp', // code is VARCHAR(3); marks the token in tg-admin's Tokens page
        ], 'trongate_tokens');

        return $token;
    }

    function delete_token(string $token): void {
        $this->db->query_bind('DELETE FROM trongate_tokens WHERE token = :token', ['token' => $token]);
    }

    /**
     * When this token expires, or null when it is gone or already expired
     */
    function token_expiry(string $token): ?int {
        $sql = 'SELECT expiry_date FROM trongate_tokens WHERE token = :token AND expiry_date > :now LIMIT 1';
        $rows = $this->db->query_bind($sql, ['token' => $token, 'now' => time()], 'object');

        return isset($rows[0]) ? (int) $rows[0]->expiry_date : null;
    }
}
