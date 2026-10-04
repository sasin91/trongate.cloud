<?php

require_once APPPATH . 'modules/platform/traits/Platform_actions.php';

/**
 * @property-read Db $db
 */
class Webhooks_model extends Model {
    use Platform_actions;

    /**
     * Apps (not deleted, in a project that is not) built from this repository
     * and branch. source_url is https://github.com/{owner}/{name}, compared
     * case-insensitively like GitHub does.
     */
    function apps_on_branch(string $source_url, string $branch): array {
        $sql = <<<SQL
            SELECT a.id, a.project_id, a.auto_deploy
            FROM app a
            JOIN project p ON p.id = a.project_id
            WHERE a.source_provider = 'github'
              AND LOWER(TRIM(TRAILING '/' FROM a.source_url)) = :source_url
              AND COALESCE(NULLIF(a.source_branch, ''), 'main') = :branch
              AND a.deleted_at IS NULL AND p.deleted_at IS NULL
            ORDER BY a.id
        SQL;

        return $this->db->query_bind($sql, ['source_url' => strtolower($source_url), 'branch' => $branch], 'object');
    }
}
