<?php

/**
 * OAuth grants and tokens for models (tables mcp_oauth_client, mcp_oauth_grant,
 * mcp_oauth_token). Shared by the OAuth server (mcp-oauth), the MCP endpoint,
 * which checks access tokens, and the provider pages, which list and revoke
 * a customer's connections.
 *
 * Tokens are random and stored only as sha256. Codes and refresh tokens are
 * single use; presenting a spent one again revokes the whole grant, since
 * someone else must have a copy (OAuth 2.1, section 4.3.1 / 6.1).
 *
 * @property-read Db $db
 */
trait Mcp_grants {

    const CODE_SECONDS = 300;
    const ACCESS_SECONDS = 3600;
    const REFRESH_SECONDS = 30 * 86400;

    const TOKEN_PREFIXES = ['code' => 'tcc_', 'access' => 'tca_', 'refresh' => 'tcr_'];

    /**
     * Create a token of a kind for a grant and return it; only its hash is kept.
     */
    function issue_token(int $grant_id, string $kind, ?string $code_challenge = null, ?string $redirect_uri = null): string {
        $token = self::TOKEN_PREFIXES[$kind] . bin2hex(random_bytes(32));
        $seconds = match ($kind) {
            'code' => self::CODE_SECONDS,
            'access' => self::ACCESS_SECONDS,
            default => self::REFRESH_SECONDS,
        };

        $sql = <<<SQL
            INSERT INTO mcp_oauth_token (grant_id, kind, token_hash, code_challenge, redirect_uri, expires_at)
            VALUES (:grant_id, :kind, :token_hash, :code_challenge, :redirect_uri, NOW() + INTERVAL %d SECOND)
        SQL;
        $this->db->query_bind(sprintf($sql, $seconds), [
            'grant_id' => $grant_id,
            'kind' => $kind,
            'token_hash' => hash('sha256', $token),
            'code_challenge' => $code_challenge,
            'redirect_uri' => $redirect_uri,
        ]);

        return $token;
    }

    /**
     * Spend a code or refresh token: its row (with grant, client and customer
     * fields), or false when it is unknown, expired, of another kind or its
     * grant was revoked. A token spent before revokes its grant.
     */
    function spend_token(string $kind, string $token): object|false {
        if (!str_starts_with($token, self::TOKEN_PREFIXES[$kind])) {
            return false;
        }

        $sql = <<<SQL
            SELECT t.id, t.grant_id, t.code_challenge, t.redirect_uri, t.used_at,
                t.expires_at > NOW() AS live,
                g.customer_id, g.scope, g.revoked_at,
                c.id AS client_pk, c.client_id, c.client_secret_hash
            FROM mcp_oauth_token t
            JOIN mcp_oauth_grant g ON g.id = t.grant_id
            JOIN mcp_oauth_client c ON c.id = g.client_id
            WHERE t.token_hash = :token_hash AND t.kind = :kind
            FOR UPDATE
        SQL;

        $row = $this->db->transaction(function () use ($sql, $kind, $token) {
            $rows = $this->db->query_bind($sql, ['token_hash' => hash('sha256', $token), 'kind' => $kind], 'object');
            $row = $rows[0] ?? null;

            if ($row === null) {
                return ['row' => false];
            }

            if ($row->used_at !== null) {
                $this->db->query_bind('UPDATE mcp_oauth_grant SET revoked_at = COALESCE(revoked_at, NOW()) WHERE id = :id', ['id' => (int) $row->grant_id]);

                return ['row' => false];
            }

            $this->db->query_bind('UPDATE mcp_oauth_token SET used_at = NOW() WHERE id = :id', ['id' => (int) $row->id]);

            return ['row' => (int) $row->live === 1 && $row->revoked_at === null ? $row : false];
        });

        return $row['row'];
    }

    /**
     * The grant behind a live access token, with its customer (active and
     * onboarded) and client, or false
     */
    function access_grant(string $token): object|false {
        if (!str_starts_with($token, self::TOKEN_PREFIXES['access'])) {
            return false;
        }

        $sql = <<<SQL
            SELECT g.id AS grant_id, g.customer_id, g.scope, g.last_used_at,
                c.provider, c.client_name
            FROM mcp_oauth_token t
            JOIN mcp_oauth_grant g ON g.id = t.grant_id
            JOIN mcp_oauth_client c ON c.id = g.client_id
            JOIN customer cu ON cu.id = g.customer_id
            WHERE t.token_hash = :token_hash
              AND t.kind = 'access'
              AND t.expires_at > NOW()
              AND g.revoked_at IS NULL
              AND cu.active = 1
              AND cu.onboarded_at IS NOT NULL
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['token_hash' => hash('sha256', $token)], 'object');
        $grant = $rows[0] ?? false;

        if ($grant !== false && ($grant->last_used_at === null || strtotime((string) $grant->last_used_at) < time() - 60)) {
            $this->db->query_bind('UPDATE mcp_oauth_grant SET last_used_at = NOW() WHERE id = :id', ['id' => (int) $grant->grant_id]);
        }

        return $grant;
    }

    /**
     * The customer's live connections, newest first (one provider, or all)
     *
     * @return list<object> id, provider, client_name, created_at, last_used_at
     */
    function customer_grants(int $customer_id, ?string $provider = null): array {
        $sql = <<<SQL
            SELECT g.id, c.provider, c.client_name, g.created_at, g.last_used_at
            FROM mcp_oauth_grant g
            JOIN mcp_oauth_client c ON c.id = g.client_id
            WHERE g.customer_id = :customer_id
              AND g.revoked_at IS NULL
              AND (:provider_any = 1 OR c.provider = :provider)
            ORDER BY g.id DESC
        SQL;

        return $this->db->query_bind($sql, [
            'customer_id' => $customer_id,
            'provider_any' => $provider === null ? 1 : 0,
            'provider' => (string) $provider,
        ], 'object');
    }

    /**
     * Revoke one of the customer's grants; its tokens stop working at once.
     * False when it is not theirs or already revoked.
     */
    function revoke_grant(int $customer_id, int $grant_id): bool {
        $live = $this->db->query_bind(
            'SELECT id FROM mcp_oauth_grant WHERE id = :id AND customer_id = :customer_id AND revoked_at IS NULL',
            ['id' => $grant_id, 'customer_id' => $customer_id],
            'column'
        );

        if ($live === false) {
            return false;
        }

        $this->db->query_bind('UPDATE mcp_oauth_grant SET revoked_at = NOW() WHERE id = :id', ['id' => $grant_id]);
        $this->db->query_bind('DELETE FROM mcp_oauth_token WHERE grant_id = :id', ['id' => $grant_id]);

        return true;
    }
}
