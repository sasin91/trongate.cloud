<?php

require_once APPPATH . 'modules/platform/traits/Platform_actions.php';

/**
 * @property-read Db $db
 */
class Webhooks_model extends Model {
    use Platform_actions;

    /**
     * The fields a webhook needs, by app id alone (the signature proves access).
     */
    function find_webhook_app(int $app_id): object|false {
        $sql = <<<SQL
            SELECT id, project_id, source_url, source_branch, webhook_secret, auto_deploy
            FROM app
            WHERE id = :app_id AND deleted_at IS NULL
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['app_id' => $app_id], 'object');

        return $rows[0] ?? false;
    }
}
