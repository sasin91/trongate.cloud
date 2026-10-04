<?php

require_once APPPATH . 'modules/platform/traits/Platform_actions.php';

/**
 * @property-read Db $db
 */
class Webhooks_model extends Model {
    use Platform_actions;

    /**
     * The fields a webhook needs, by project id alone (the signature proves access).
     */
    function find_webhook_project(int $project_id): object|false {
        $sql = <<<SQL
            SELECT id, team_id, source_url, source_branch, webhook_secret, auto_deploy
            FROM project
            WHERE id = :project_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['project_id' => $project_id], 'object');

        return $rows[0] ?? false;
    }
}
