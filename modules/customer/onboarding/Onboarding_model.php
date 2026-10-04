<?php

require_once __DIR__ . '/../traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once APPPATH . 'modules/apps/traits/App_records.php';

/**
 * @property-read Db $db
 */
class Onboarding_model extends Model {
    use Current_customer;
    use Platform_actions;
    use App_records;

    function mark_onboarded(int $customer_id): bool {
        return $this->db->update($customer_id, [
            'onboarded_at' => date('Y-m-d H:i:s'),
            'active' => 1,
        ], 'customer');
    }

    function create_project(int $customer_id, string $name): int|false {
        return $this->db->insert([
            'name' => $name,
            'owner_id' => $customer_id,
        ], 'project');
    }

    function rename_project(int $project_id, string $name): bool {
        return (bool) $this->db->update($project_id, ['name' => $name], 'project');
    }

    function add_project_member(int $project_id, int $customer_id, string $role = 'owner'): bool {
        return (bool) $this->db->insert([
            'project_id' => $project_id,
            'customer_id' => $customer_id,
            'role' => $role,
            'status' => 'active',
        ], 'project_member');
    }
}
