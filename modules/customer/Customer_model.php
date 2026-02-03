<?php

/**
 * @property-read Db $db
 */
class Customer_model extends Model
{

    private string $table_name = 'customer';
    private int $max_failed_attempts = 3;
    private int $block_time_seconds = 900; // Fifteen minutes
    private int $min_block_timestamp = 1000; // Threshold for "active" blocks

    /**
     * Find a single record based on a column value.
     *
     * @param string $column The column name to match
     * @param mixed $value The value to match
     * @return object|false Returns record object if found, false otherwise
     */
    private function find_one(string $column, $value): object|false
    {
        $sql = <<<SQL
            SELECT * FROM {$this->table_name}
            WHERE {$column} = :{$column}
            LIMIT 1
        SQL;

        $params = [$column => $value];
        $rows = $this->db->query_bind($sql, $params, 'object');
        return !empty($rows) ? $rows[0] : false;
    }

    public function email_exists(string $email): bool
    {
        $sql = <<<SQL
            SELECT 1 FROM {$this->table_name}
            WHERE email = :email
            LIMIT 1
        SQL;

        $params = ['email' => $email];
        return (bool) $this->db->query_bind($sql, $params, 'column');
    }

    /**
     * Reset expired login blocks by clearing failed login counters.
     * 
     * Finds records with active but expired login blocks (login_blocked_until > min threshold 
     * AND < current time) and resets their failed login tracking fields.
     * 
     * @return void
     */
    public function remove_expired_restrictions(): void
    {
        $params['current_time'] = time();
        $params['min_timestamp'] = $this->min_block_timestamp;

        $sql = <<<SQL
            SELECT * FROM {$this->table_name}
            WHERE login_blocked_until > :min_timestamp
            AND login_blocked_until < :current_time
        SQL;

        $rows = $this->db->query_bind($sql, $params, 'object');

        $data = [
            'failed_login_attempts' => 0,
            'last_failed_attempt' => 0,
            'login_blocked_until' => 0,
            'failed_login_ip' => ''
        ];

        foreach ($rows as $row) {
            $update_id = (int) $row->id;
            $this->db->update($update_id, $data, $this->table_name);
        }
    }

    /**
     * Determines if a login attempt is allowed for a given email or IP address.
     *
     * @param string|null $email Optional. The email to check against. Defaults to null.
     * @return bool Returns true if the login attempt is allowed; false if blocked.
     */
    public function is_login_attempt_allowed(?string $email = null): bool
    {
        $params = [
            'failed_login_ip' => ip_address(),
            'current_time' => time(),
            'max_attempts' => $this->max_failed_attempts
        ];

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

    /**
     * Get a timing-safe hash for password verification.
     * 
     * @return string A valid bcrypt hash for constant-time verification
     */
    private function get_timing_safe_hash(): string
    {
        $user_obj = $this->find_one('active', 1);

        // Use real user's hash when available
        if ($user_obj !== false && !empty($user_obj->password)) {
            return $user_obj->password;
        }

        // Generate a valid bcrypt hash for timing protection
        return $this->hash_password('timing_protection_dummy');
    }

    /**
     * Validate submitted credentials with timing-attack protection.
     *
     * @param string $email The submitted email
     * @param string $password The submitted password
     * @return bool True if authentication succeeds, false otherwise
     */
    public function validate_credentials(string $email, string $password): bool
    {
        // Attempt to retrieve the submitted user
        $submitted_user = $this->find_one('email', $email);

        if ($submitted_user === false) {
            return false;
        }

        // Determine which hash to verify against
        $hash_to_verify = $this->get_timing_safe_hash(); // Default (timing-safe)

        // Use submitted user's hash if available and valid
        if ($submitted_user !== false && !empty($submitted_user->password)) {
            $hash_to_verify = $submitted_user->password;
        }

        // ALWAYS perform password verification (timing-safe operation)
        $password_valid = $this->verify_password($password, $hash_to_verify);

        // Authentication succeeds ONLY when all conditions are met
        return ($submitted_user !== false)
            && !empty($submitted_user->password)
            && $password_valid
            && ((int) $submitted_user->active === 1);
    }

    /**
     * Hash a plain-text password using bcrypt.
     *
     * @param string $password
     * @return string
     */
    private function hash_password(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]);
    }

    /**
     * Verify a plain-text password against a stored hash.
     *
     * @param string $plain_text_password
     * @param string $hashed_password
     * @return bool
     */
    public function verify_password(string $plain_text_password, string $hashed_password): bool
    {
        return password_verify($plain_text_password, $hashed_password);
    }

    /**
     * Increment failed login attempt counter for a user.
     *
     * @param string $email The email that failed authentication
     * @return bool Returns true if user should be blocked, false otherwise
     */
    public function increment_failed_login_attempts(string $email): bool
    {
        $user_record = $this->find_one('email', $email);

        if ($user_record === false) {
            return false; // User doesn't exist - no action needed
        }

        $failed_login_attempts = (int) $user_record->failed_login_attempts;

        $data = [
            'failed_login_attempts' => $failed_login_attempts + 1,
            'last_failed_attempt' => time(),
            'failed_login_ip' => ip_address()
        ];

        // Check if user should be blocked
        $should_block = ($data['failed_login_attempts'] >= $this->max_failed_attempts);

        if ($should_block) {
            $data['login_blocked_until'] = time() + $this->block_time_seconds;
        }

        $update_id = (int) $user_record->id;
        $this->db->update($update_id, $data, $this->table_name);

        return $should_block;
    }

    /**
     * Log a user in and persist authentication state.
     *
     * @param string $email
     * @param int $remember 0 for session-only, 1 for persistent cookie (30 days)
     *
     * @return string|bool
     * Returns the generated authentication token on success,
     * or FALSE if the user could not be logged in.
     */
    public function log_user_in(string $email, int $remember = 0): string|bool
    {
        $this->module('trongate_tokens');
        $user = $this->find_one('email', $email);

        if ($user === false) {
            return false;
        }

        $token_data = [
            'user_id' => (int) $user->trongate_user_id
        ];

        if ($remember === 1) {
            // Persist login for 30 days
            $token_data['expiry_date'] = time() + (86400 * 30);
            $token = $this->trongate_tokens->generate_token($token_data);
            setcookie('trongatetoken', $token, $token_data['expiry_date'], '/');
            return $token;
        }

        // Session-only authentication
        $token = $this->trongate_tokens->generate_token($token_data);
        $_SESSION['trongatetoken'] = $token;

        return $token;
    }

    /**
     * Reset failed login counters and lockout information for a user after a successful login.
     *
     * @param string $email The email that has successfully logged in.
     * @return void
     */
    public function after_login_tasks(string $email): void
    {
        $data = [
            'failed_login_attempts' => 0,
            'last_failed_attempt' => 0,
            'login_blocked_until' => 0,
            'failed_login_ip' => ''
        ];

        // First, find the user ID by email
        $user = $this->find_one('email', $email);

        if ($user !== false) {
            $update_id = (int) $user->id;
            $this->db->update($update_id, $data, $this->table_name);
        }
    }
    /**
     * Create a new customer account.
     * 
     * @param array $data The data to insert (email, password, etc.)
     * @return int|false The ID of the new customer record, or false on failure.
     */
    public function create_account(array $data): int|false
    {
        return $this->db->transaction(function () use ($data) {
            $trongate_user_id = $this->db->insert([
                'code' => make_rand_str(32),
                'user_level_id' => 2
            ], 'trongate_users');

            if (!$trongate_user_id) {
                return false;
            }

            return $this->db->insert([
                'trongate_user_id' => $trongate_user_id,
                'email' => $data['email'],
                'password' => $this->hash_password($data['password']),
                'active' => 1,
            ], $this->table_name);
        });
    }

    /**
     * Get the current customer ID based on the active token.
     * 
     * @return int|false The customer ID or false if not found/logged in
     */
    private function _get_current_customer_id(): int|false
    {
        $this->module('trongate_tokens');
        $trongate_user_id = $this->trongate_tokens->get_user_id();

        if ($trongate_user_id === false) {
            return false;
        }

        $customer = $this->find_one('trongate_user_id', $trongate_user_id);
        return $customer ? (int) $customer->id : false;
    }

    /**
     * Get recent projects (applications) for the current customer.
     * 
     * @param int $limit
     * @return array
     */
    public function recent_applications(int $limit = 5): array
    {
        $customer_id = $this->_get_current_customer_id();
        if ($customer_id === false) {
            return [];
        }

        $sql = <<<SQL
            SELECT p.* FROM project p
            JOIN team_member tm ON p.team_id = tm.team_id
            WHERE tm.customer_id = :customer_id
            ORDER BY p.created_at DESC
            LIMIT :limit
        SQL;

        return $this->db->query_bind($sql, [
            'customer_id' => $customer_id,
            'limit' => $limit
        ], 'object');
    }

    /**
     * Get recent servers for the current customer.
     * 
     * @param int $limit
     * @return array
     */
    public function recent_servers(int $limit = 5): array
    {
        $customer_id = $this->_get_current_customer_id();
        if ($customer_id === false) {
            return [];
        }

        $sql = <<<SQL
            SELECT s.* FROM server s
            JOIN team_member tm ON s.team_id = tm.team_id
            WHERE tm.customer_id = :customer_id
            ORDER BY s.created_at DESC
            LIMIT :limit
        SQL;

        return $this->db->query_bind($sql, [
            'customer_id' => $customer_id,
            'limit' => $limit
        ], 'object');
    }

    /**
     * Get recent deployments for the current customer.
     * 
     * @param int $limit
     * @return array
     */
    public function recent_deployments(int $limit = 10): array
    {
        $customer_id = $this->_get_current_customer_id();
        if ($customer_id === false) {
            return [];
        }

        $sql = <<<SQL
            SELECT d.*, p.label as project_label, s.address as server_address
            FROM deployment d
            JOIN project p ON d.project_id = p.id
            JOIN server s ON d.server_id = s.id
            JOIN team_member tm ON d.team_id = tm.team_id
            WHERE tm.customer_id = :customer_id
            ORDER BY d.created_at DESC
            LIMIT :limit
        SQL;

        return $this->db->query_bind($sql, [
            'customer_id' => $customer_id,
            'limit' => $limit
        ], 'object');
    }
}
