<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once __DIR__ . '/traits/Setup_sql_records.php';

/**
 * @property-read Db $db
 */
class Setup_sql_model extends Model {
    use Current_customer;
    use Platform_actions;
    use Setup_sql_records;
}
