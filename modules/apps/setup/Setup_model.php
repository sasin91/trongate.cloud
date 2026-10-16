<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';

/**
 * @property-read Db $db
 */
class Setup_model extends Model {
    use Current_customer;
    use Platform_actions;

    /**
     * Hide a failed setup SQL's alert: the SQL that did not run is cleared,
     * the error kept
     */
    function dismiss_setup_sql(int $app_id): void {
        $this->db->query_bind("UPDATE app SET setup_sql = NULL, setup_sql_status = 'dismissed' WHERE id = :app_id AND setup_sql_status = 'failed'", ['app_id' => $app_id]);
    }
}
