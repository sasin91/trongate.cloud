<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once APPPATH . 'modules/platform/traits/App_upgrades.php';

/**
 * @property-read Db $db
 */
class Upgrade_model extends Model {
    use Current_customer;
    use Platform_actions;
    use App_upgrades;
}
