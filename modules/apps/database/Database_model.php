<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once __DIR__ . '/traits/App_database_log.php';

/**
 * @property-read Db $db
 */
class Database_model extends Model {
    use Current_customer;
    use Platform_actions;
    use App_database_log;
}
