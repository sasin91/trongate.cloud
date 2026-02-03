<?php

require_once __DIR__ . '/../traits/Current_customer.php';

/**
 * @property-read Db $db
 */
class Onboarding_model extends Model
{
    use Current_customer;

    public function is_onboarded(int $customer_id): bool
    {
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

    public function mark_onboarded(int $customer_id): bool
    {
        return $this->db->update($customer_id, [
            'onboarded_at' => date('Y-m-d H:i:s'),
            'active' => 1
        ], 'customer');
    }

    public function get_owned_teams(int $customer_id): array
    {
        return $this->db->query_bind(
            "SELECT * FROM team WHERE owner_id = :customer_id",
            ['customer_id' => $customer_id],
            'object'
        );
    }

    public function find(int $customer_id): object
    {
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

    public function create_team(int $customer_id, string $name): int|false
    {
        return $this->db->insert([
            'name' => $name,
            'owner_id' => $customer_id
        ], 'team');
    }

    public function add_provider(int $team_id, array $provider_data): bool
    {
        // Fetch current providers
        $team = $this->db->get_where($team_id, 'team');
        if (!$team) return false;

        $providers = json_decode($team->providers ?? '[]', true);

        // Basic check to avoid exact duplicates if needed, but for now just appending
        $providers[] = $provider_data;

        $data = ['providers' => json_encode($providers)];
        return $this->db->update($team_id, $data, 'team');
    }

    public function create_project(int $team_id, string $label, string $type): int|false
    {
        $data = [
            'team_id' => $team_id,
            'label' => $label,
            'type' => $type
        ];
        return $this->db->insert($data, 'project');
    }
}
