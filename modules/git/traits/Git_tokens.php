<?php

/**
 * Access tokens for trongate.cloud git (app_git_token): a member of the
 * app's project makes one on the app's Git tab and uses it as the password
 * for HTTPS clone and push. The token is shown once; only its SHA-256 and its
 * last four characters are kept. A token stops working when it is revoked,
 * when its app is deleted, or when its maker leaves the project.
 *
 * The model must have $this->db.
 */
trait Git_tokens {

    /**
     * Make a token for the repository of app $app_id; returns the token itself
     */
    function create_git_token(int $app_id, int $customer_id, string $label): string {
        $token = 'tcg_' . bin2hex(random_bytes(20));

        $this->db->insert([
            'app_id' => $app_id,
            'customer_id' => $customer_id,
            'label' => $label,
            'token_hash' => hash('sha256', $token),
            'last_four' => substr($token, -4),
        ], 'app_git_token');

        return $token;
    }

    /**
     * The repository's tokens that still work, newest first, with who made them
     *
     * @return list<object>
     */
    function app_git_tokens(int $app_id): array {
        $sql = <<<SQL
            SELECT t.id, t.label, t.last_four, t.created_at, t.last_used_at, c.email AS customer_email
            FROM app_git_token t
            JOIN customer c ON c.id = t.customer_id
            WHERE t.app_id = :app_id
              AND t.revoked_at IS NULL
            ORDER BY t.id DESC
        SQL;

        return $this->db->query_bind($sql, ['app_id' => $app_id], 'object') ?: [];
    }

    /**
     * Revoke one of the repository's tokens; false when there is no such token
     */
    function revoke_git_token(int $app_id, int $token_id): bool {
        $sql = 'UPDATE app_git_token SET revoked_at = NOW() WHERE id = :id AND app_id = :app_id AND revoked_at IS NULL';
        $this->db->query_bind($sql, ['id' => $token_id, 'app_id' => $app_id]);

        $rows = $this->db->query_bind('SELECT revoked_at FROM app_git_token WHERE id = :id AND app_id = :app_id', ['id' => $token_id, 'app_id' => $app_id], 'object');

        return !empty($rows[0]->revoked_at);
    }

    /**
     * The token that opens repository p{app_id}.git: not revoked, its app on
     * trongate.cloud git and not deleted, its maker still a project member.
     * False for anything else.
     */
    function find_git_token(int $app_id, string $token): object|false {
        if (!preg_match('/^tcg_[0-9a-f]{40}$/', $token)) {
            return false;
        }

        $sql = <<<SQL
            SELECT t.id, t.app_id, t.customer_id, a.project_id
            FROM app_git_token t
            JOIN app a ON a.id = t.app_id
            JOIN project p ON p.id = a.project_id
            JOIN project_member pm ON pm.project_id = p.id AND pm.customer_id = t.customer_id
            WHERE t.token_hash = :token_hash
              AND t.app_id = :app_id
              AND t.revoked_at IS NULL
              AND a.source_provider = 'internal'
              AND a.deleted_at IS NULL
              AND p.deleted_at IS NULL
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['token_hash' => hash('sha256', $token), 'app_id' => $app_id], 'object');

        return $rows[0] ?? false;
    }

    function mark_git_token_used(int $token_id): void {
        $this->db->query_bind('UPDATE app_git_token SET last_used_at = NOW() WHERE id = :id', ['id' => $token_id]);
    }
}
