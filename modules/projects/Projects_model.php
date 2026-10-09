<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once APPPATH . 'modules/platform/live/App_traffic.php';

/**
 * Projects, their members and apps via Platform_actions (membership-checked by the controller).
 *
 * @property-read Db $db
 */
class Projects_model extends Model {
    use Current_customer;
    use Platform_actions;

    /**
     * Traffic windows (App_traffic) of the project's apps over the last $hours hours
     *
     * @return list<array<string, int|string>>
     */
    function traffic_rows(int $project_id, int $hours = App_traffic::HOURS): array {
        $sql = 'SELECT t.* FROM app_traffic t
                JOIN app a ON a.id = t.app_id
                WHERE a.project_id = :project_id AND a.deleted_at IS NULL
                  AND t.bucket >= UTC_TIMESTAMP() - INTERVAL :hours HOUR
                ORDER BY t.bucket';

        return $this->db->query_bind($sql, ['project_id' => $project_id, 'hours' => $hours], 'array');
    }
}
