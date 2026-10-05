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

    /**
     * Queue a build of the platform's own images (tasks/build_platform.php),
     * unless one is already waiting: the task builds the branch head when it
     * starts, so a second queued build would only repeat it.
     *
     * @return int|null The new task's id, or null when one was already queued
     */
    function queue_platform_build(string $commit_sha): ?int {
        return $this->db->transaction(function () use ($commit_sha) {
            $sql = <<<SQL
                SELECT id FROM tasks
                WHERE script = 'build_platform'
                  AND started_at IS NULL AND completed_at IS NULL AND failed_at IS NULL
                LIMIT 1
                FOR UPDATE
            SQL;

            if ($this->db->query_bind($sql, [], 'object') !== []) {
                return null;
            }

            return $this->db->insert([
                'project_id' => null,
                'script' => 'build_platform',
                'payload' => json_encode(['commit_sha' => $commit_sha]),
            ], 'tasks');
        });
    }
}
