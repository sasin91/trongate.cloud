<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once APPPATH . 'modules/platform/traits/App_upgrades.php';
require_once __DIR__ . '/traits/App_records.php';

/**
 * @property-read Db $db
 */
class Apps_model extends Model {
    use Current_customer;
    use Platform_actions;
    use App_upgrades;
    use App_records;

    /**
     * The newest successful deployment that is not the current one (the default rollback target).
     */
    function rollback_target(object $app): object|false {
        return $this->_previous_successful_deployment($app);
    }

    /**
     * The app's sampled log windows (App_log_rates) of the last $hours hours
     *
     * @return list<array{bucket: string, total: int|string, warnings: int|string, errors: int|string}>
     */
    function log_rate_rows(int $app_id, int $hours = 24): array {
        $sql = 'SELECT bucket, total, warnings, errors FROM app_log_rate
                WHERE app_id = :app_id AND bucket >= UTC_TIMESTAMP() - INTERVAL :hours HOUR
                ORDER BY bucket';

        return $this->db->query_bind($sql, ['app_id' => $app_id, 'hours' => $hours], 'array');
    }

}
