<?php

require_once __DIR__ . '/../traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once APPPATH . 'modules/projects/traits/Project_records.php';

/**
 * @property-read Db $db
 */
class Onboarding_model extends Model {
    use Current_customer;
    use Platform_actions;
    use Project_records;

    function mark_onboarded(int $customer_id): bool {
        return $this->db->update($customer_id, [
            'onboarded_at' => date('Y-m-d H:i:s'),
            'active' => 1,
        ], 'customer');
    }

    function create_team(int $customer_id, string $name): int|false {
        return $this->db->insert([
            'name' => $name,
            'owner_id' => $customer_id,
        ], 'team');
    }

    function add_team_member(int $team_id, int $customer_id, string $role = 'owner'): bool {
        return (bool) $this->db->insert([
            'team_id' => $team_id,
            'customer_id' => $customer_id,
            'role' => $role,
            'status' => 'active',
        ], 'team_member');
    }
}
