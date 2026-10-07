<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once APPPATH . 'modules/mcp/traits/Mcp_grants.php';

/**
 * @property-read Db $db
 */
class Claude_model extends Model {
    use Current_customer;
    use Platform_actions;
    use Mcp_grants;
}
