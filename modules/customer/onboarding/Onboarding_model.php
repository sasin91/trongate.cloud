<?php

require_once __DIR__ . '/../traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once APPPATH . 'modules/apps/traits/App_records.php';

/**
 * Projects are created through Platform_actions::create_project (namespace + owner membership).
 *
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
}
