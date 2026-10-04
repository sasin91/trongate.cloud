<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';

/**
 * Reads deployments and their logs via Platform_actions: found through the
 * customer's project membership (find_member_deployment), then project-checked.
 *
 * @property-read Db $db
 */
class Deployments_model extends Model {
    use Current_customer;
    use Platform_actions;
}
