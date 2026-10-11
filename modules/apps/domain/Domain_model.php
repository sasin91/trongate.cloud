<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once APPPATH . 'modules/apps/traits/App_records.php';

/**
 * @property-read Db $db
 */
class Domain_model extends Model {
    use Current_customer;
    use Platform_actions;
    use App_records;
}
