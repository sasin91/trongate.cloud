<?php

require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once APPPATH . 'modules/git/traits/Git_tokens.php';

/**
 * @property-read Db $db
 */
class Git_model extends Model {
    use Platform_actions;
    use Git_tokens;

    /**
     * Apps built from repository p{repo_app_id}.git on this branch (its
     * production app, and a staging app made from it), not deleted
     *
     * @return list<object>
     */
    function internal_apps_on_branch(int $repo_app_id, string $branch): array {
        $sql = <<<SQL
            SELECT a.id, a.project_id, a.auto_deploy
            FROM app a
            JOIN project p ON p.id = a.project_id
            WHERE a.source_provider = 'internal'
              AND a.source_url LIKE :url_suffix
              AND a.source_branch = :branch
              AND a.deleted_at IS NULL
              AND p.deleted_at IS NULL
        SQL;

        return $this->db->query_bind($sql, ['url_suffix' => '%/git/p' . $repo_app_id . '.git', 'branch' => $branch], 'object') ?: [];
    }
}
