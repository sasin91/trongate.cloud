<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once __DIR__ . '/traits/App_records.php';

/**
 * @property-read Db $db
 */
class Apps_model extends Model {
    use Current_customer;
    use Platform_actions;
    use App_records;

    /**
     * The newest successful deployment that is not the current one (the default rollback target).
     */
    function rollback_target(object $app): object|false {
        return $this->_previous_successful_deployment($app);
    }

}
