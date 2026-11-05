<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';

/**
 * @property-read Db $db
 */
class Repository_model extends Model {
    use Current_customer;
    use Platform_actions;

    /**
     * An import_git task of the app that has not finished yet
     */
    function git_import_waiting(int $app_id): bool {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM tasks
            WHERE app_id = :app_id
              AND script = 'import_git'
              AND completed_at IS NULL
              AND failed_at IS NULL
        SQL;

        return (int) $this->db->query_bind($sql, ['app_id' => $app_id], 'column') > 0;
    }
}
