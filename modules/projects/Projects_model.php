<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';

/**
 * Projects, their members and apps via Platform_actions (membership-checked by the controller).
 *
 * @property-read Db $db
 */
class Projects_model extends Model {
    use Current_customer;
    use Platform_actions;
}
