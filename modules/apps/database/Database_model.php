<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';

/**
 * @property-read Db $db
 */
class Database_model extends Model {
    use Current_customer;
    use Platform_actions;

    /**
     * The shared MariaDB server an app's database was placed on (see Platform_task::database_server)
     */
    function database_server(int $id): object|false {
        $server = $this->db->get_where($id, 'database_server');

        return is_object($server) ? $server : false;
    }
}
