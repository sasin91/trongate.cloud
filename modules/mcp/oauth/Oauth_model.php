<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/mcp/traits/Mcp_grants.php';

/**
 * Clients and grants for the OAuth server (mcp-oauth).
 *
 * @property-read Db $db
 */
class Oauth_model extends Model {
    use Current_customer;
    use Mcp_grants;

    /** Registrations accepted per hour from everyone together */
    const REGISTRATIONS_PER_HOUR = 200;

    /**
     * Store a client registered by dynamic client registration; returns its client_id
     *
     * @param list<string> $redirect_uris
     */
    function register_client(string $provider, string $client_name, array $redirect_uris, ?string $client_secret): string {
        $client_id = 'tc_' . bin2hex(random_bytes(16));

        $this->db->insert([
            'client_id' => $client_id,
            'client_secret_hash' => $client_secret === null ? null : hash('sha256', $client_secret),
            'provider' => $provider,
            'client_name' => $client_name,
            'redirect_uris' => json_encode($redirect_uris, JSON_UNESCAPED_SLASHES),
        ], 'mcp_oauth_client');

        return $client_id;
    }

    function find_client(string $client_id): object|false {
        $rows = $this->db->query_bind('SELECT * FROM mcp_oauth_client WHERE client_id = :client_id', ['client_id' => $client_id], 'object');

        return $rows[0] ?? false;
    }

    function too_many_registrations(): bool {
        $count = $this->db->query('SELECT COUNT(*) FROM mcp_oauth_client WHERE created_at > NOW() - INTERVAL 1 HOUR', 'column');

        return (int) $count >= self::REGISTRATIONS_PER_HOUR;
    }

    /**
     * Forget clients nobody connected within a day, and tokens long expired
     */
    function prune(): void {
        $this->db->query(<<<SQL
            DELETE c FROM mcp_oauth_client c
            LEFT JOIN mcp_oauth_grant g ON g.client_id = c.id
            WHERE g.id IS NULL AND c.created_at < NOW() - INTERVAL 1 DAY
        SQL);
        $this->db->query('DELETE FROM mcp_oauth_token WHERE expires_at < NOW() - INTERVAL 1 DAY');
    }

    /**
     * The customer's live grant for this client, or a new one
     */
    function grant_for(int $client_pk, int $customer_id, string $scope): int {
        $id = $this->db->query_bind(
            'SELECT id FROM mcp_oauth_grant WHERE client_id = :client_id AND customer_id = :customer_id AND revoked_at IS NULL ORDER BY id DESC LIMIT 1',
            ['client_id' => $client_pk, 'customer_id' => $customer_id],
            'column'
        );

        if ($id !== false) {
            return (int) $id;
        }

        return $this->db->insert(['client_id' => $client_pk, 'customer_id' => $customer_id, 'scope' => $scope], 'mcp_oauth_grant');
    }
}
