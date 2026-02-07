<?php

require_once __DIR__ . '/../traits/Current_customer.php';

/**
 * @property-read Db $db
 */
class Onboarding_model extends Model {
    use Current_customer;

    function is_onboarded(int $customer_id): bool {
        $is_active = $this->db->query_bind(
            <<<SQL
                SELECT 1
                FROM customer
                WHERE id = :customer_id
                AND active = 1
                AND onboarded_at IS NOT NULL
                LIMIT 1
            SQL,
            ['customer_id' => $customer_id],
            'column'
        );

        return (bool) $is_active;
    }

    function mark_onboarded(int $customer_id): bool {
        return $this->db->update($customer_id, [
            'onboarded_at' => date('Y-m-d H:i:s'),
            'active' => 1
        ], 'customer');
    }

    function get_owned_teams(int $customer_id): array {
        return $this->db->query_bind(
            "SELECT * FROM team WHERE owner_id = :customer_id",
            ['customer_id' => $customer_id],
            'object'
        );
    }

    function find(int $customer_id): object {
        return $this->db->query_bind(
            <<<SQL
                SELECT * 
                FROM customer
                WHERE id = :customer_id
                LIMIT 1
            SQL,
            ['customer_id' => $customer_id],
            'object'
        );
    }

    function create_team(int $customer_id, string $name): int|false {
        return $this->db->insert([
            'name' => $name,
            'owner_id' => $customer_id
        ], 'team');
    }

    function add_team_member(int $team_id, int $customer_id, string $role = 'owner'): bool {
        return $this->db->insert([
            'team_id' => $team_id,
            'customer_id' => $customer_id,
            'role' => $role,
            'status' => 'active'
        ], 'team_member');
    }

    function add_team_service(int $team_id, string $service_type, string $service_id): bool {
        // Fetch current services
        $team = $this->db->get_where($team_id, 'team');
        if (!$team) return false;

        $services = json_decode($team->services ?? '[]', true);

        $services[] = [
            'type' => $service_type,
            'id' => $service_id
        ];

        $data = ['services' => json_encode($services)];
        return $this->db->update($team_id, $data, 'team');
    }

    function create_project(int $team_id, string $label, string $type, string $source_url, string $source_provider, string $source_branch = 'main', ?string $domain = null): int|false {
        return $this->db->insert([
            'team_id' => $team_id,
            'label' => $label,
            'type' => $type,
            'source_url' => $source_url,
            'source_provider' => $source_provider,
            'source_branch' => $source_branch ?? 'main',
            'domain' => $domain
        ], 'project');
    }
}
