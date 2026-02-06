<?php

require_once __DIR__ . '/traits/Current_customer.php';

/**
 * @property-read Db $db
 */
class Customer_model extends Model {
    use Current_customer;

    private string $table_name = 'customer';
    private int $max_failed_attempts = 3;
    private int $block_time_seconds = 900;
    private int $min_block_timestamp = 1000;

    function _find_one(string $column, $value): object|false {
        $sql = <<<SQL
            SELECT * FROM {$this->table_name}
            WHERE {$column} = :{$column}
            LIMIT 1
        SQL;
        $params = [$column => $value];
        $rows = $this->db->query_bind($sql, $params, 'object');
        return !empty($rows) ? $rows[0] : false;
    }

    function email_exists(string $email): bool {
        $sql = <<<SQL
            SELECT 1 FROM {$this->table_name}
            WHERE email = :email
            LIMIT 1
        SQL;
        $params = ['email' => $email];
        return (bool) $this->db->query_bind($sql, $params, 'column');
    }

    function remove_expired_restrictions(): void {
        $params = ['current_time' => time(), 'min_timestamp' => $this->min_block_timestamp];
        $sql = <<<SQL
            SELECT * FROM {$this->table_name}
            WHERE login_blocked_until > :min_timestamp
            AND login_blocked_until < :current_time
        SQL;
        $rows = $this->db->query_bind($sql, $params, 'object');
        $data = ['failed_login_attempts' => 0, 'last_failed_attempt' => 0, 'login_blocked_until' => 0, 'failed_login_ip' => ''];
        foreach ($rows as $row) {
            $this->db->update((int) $row->id, $data, $this->table_name);
        }
    }

    function is_login_attempt_allowed(?string $email = null): bool {
        $params = ['failed_login_ip' => ip_address(), 'current_time' => time(), 'max_attempts' => $this->max_failed_attempts];
        if ($email !== null) {
            $params['email'] = $email;
            $sql = <<<SQL
                SELECT * FROM {$this->table_name}
                WHERE (email = :email OR failed_login_ip = :failed_login_ip)
                AND failed_login_attempts >= :max_attempts
                AND login_blocked_until > :current_time
            SQL;
        } else {
            $sql = <<<SQL
                SELECT * FROM {$this->table_name}
                WHERE failed_login_ip = :failed_login_ip
                AND failed_login_attempts >= :max_attempts
                AND login_blocked_until > :current_time
            SQL;
        }
        $rows = $this->db->query_bind($sql, $params, 'object');
        return empty($rows);
    }

    function _get_timing_safe_hash(): string {
        $user_obj = $this->_find_one('active', 1);
        if ($user_obj !== false && !empty($user_obj->password)) {
            return $user_obj->password;
        }
        return $this->_hash_password('timing_protection_dummy');
    }

    function validate_credentials(string $email, string $password): bool {
        $submitted_user = $this->_find_one('email', $email);
        if ($submitted_user === false) {
            return false;
        }
        $hash_to_verify = $this->_get_timing_safe_hash();
        if ($submitted_user !== false && !empty($submitted_user->password)) {
            $hash_to_verify = $submitted_user->password;
        }
        $password_valid = $this->verify_password($password, $hash_to_verify);
        return ($submitted_user !== false) && !empty($submitted_user->password) && $password_valid && ((int) $submitted_user->active === 1);
    }

    function _hash_password(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]);
    }

    function verify_password(string $plain_text_password, string $hashed_password): bool {
        return password_verify($plain_text_password, $hashed_password);
    }

    function increment_failed_login_attempts(string $email): bool {
        $user_record = $this->_find_one('email', $email);
        if ($user_record === false) {
            return false;
        }
        $failed_login_attempts = (int) $user_record->failed_login_attempts;
        $data = ['failed_login_attempts' => $failed_login_attempts + 1, 'last_failed_attempt' => time(), 'failed_login_ip' => ip_address()];
        $should_block = ($data['failed_login_attempts'] >= $this->max_failed_attempts);
        if ($should_block) {
            $data['login_blocked_until'] = time() + $this->block_time_seconds;
        }
        $this->db->update((int) $user_record->id, $data, $this->table_name);
        return $should_block;
    }

    function log_user_in(string $email, int $remember = 0): string|bool {
        $this->module('trongate_tokens');
        $user = $this->_find_one('email', $email);
        if ($user === false) {
            return false;
        }
        $token_data = ['user_id' => (int) $user->trongate_user_id];
        if ($remember === 1) {
            $token_data['expiry_date'] = time() + (86400 * 30);
            $token = $this->trongate_tokens->generate_token($token_data);
            setcookie('trongatetoken', $token, $token_data['expiry_date'], '/');
            return $token;
        }
        $token = $this->trongate_tokens->generate_token($token_data);
        $_SESSION['trongatetoken'] = $token;
        return $token;
    }

    function after_login_tasks(string $email): void {
        $data = ['failed_login_attempts' => 0, 'last_failed_attempt' => 0, 'login_blocked_until' => 0, 'failed_login_ip' => ''];
        $user = $this->_find_one('email', $email);
        if ($user !== false) {
            $this->db->update((int) $user->id, $data, $this->table_name);
        }
    }

    function create_account(array $data): int|false {
        return $this->db->transaction(function () use ($data) {
            $trongate_user_id = $this->db->insert(['code' => make_rand_str(32), 'user_level_id' => 2], 'trongate_users');
            if (!$trongate_user_id) {
                return false;
            }
            return $this->db->insert([
                'trongate_user_id' => $trongate_user_id,
                'email' => $data['email'],
                'password' => $this->_hash_password($data['password']),
                'active' => 1,
            ], $this->table_name);
        });
    }

    function recent_projects(int $limit = 5): array {
        $customer = $this->_get_current_customer();
        if ($customer === false) return [];
        $sql = <<<SQL
            SELECT p.*,
            (SELECT s.address 
             FROM deployment d 
             JOIN server s ON d.server_id = s.id 
             WHERE d.project_id = p.id 
             ORDER BY d.created_at DESC LIMIT 1) as server_address,
            (SELECT s.status 
             FROM deployment d 
             JOIN server s ON d.server_id = s.id 
             WHERE d.project_id = p.id 
             ORDER BY d.created_at DESC LIMIT 1) as server_status
            FROM project p
            LEFT JOIN team_member tm ON p.team_id = tm.team_id
            LEFT JOIN team t ON p.team_id = t.id
            WHERE tm.customer_id = :customer_id OR t.owner_id = :customer_id
            ORDER BY p.created_at DESC LIMIT :limit
        SQL;
        return $this->db->query_bind($sql, ['customer_id' => $customer->id, 'limit' => $limit], 'object');
    }

    function recent_servers(int $limit = 5): array {
        $customer = $this->_get_current_customer();
        if ($customer === false) return [];
        $sql = <<<SQL
            SELECT s.* FROM server s
            JOIN team_member tm ON s.team_id = tm.team_id
            WHERE tm.customer_id = :customer_id
            ORDER BY s.created_at DESC LIMIT :limit
        SQL;
        return $this->db->query_bind($sql, ['customer_id' => $customer->id, 'limit' => $limit], 'object');
    }

    function recent_deployments(int $limit = 10): array {
        $customer = $this->_get_current_customer();
        if ($customer === false) return [];
        $sql = <<<SQL
            SELECT d.*, p.label as project_label, s.address as server_address
            FROM deployment d
            JOIN project p ON d.project_id = p.id
            JOIN server s ON d.server_id = s.id
            JOIN team_member tm ON d.team_id = tm.team_id
            WHERE tm.customer_id = :customer_id
            ORDER BY d.created_at DESC LIMIT :limit
        SQL;
        return $this->db->query_bind($sql, ['customer_id' => $customer->id, 'limit' => $limit], 'object');
    }
}
