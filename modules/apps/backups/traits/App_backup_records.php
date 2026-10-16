<?php

/**
 * Backups of deleted apps (app_backup, see App_backup): the ones a project
 * still shows, and the one a member may download.
 *
 * @property-read Db $db
 */
trait App_backup_records {

    /**
     * The project's deleted apps whose backup is being taken, can be
     * downloaded, or failed; newest first
     */
    function project_app_backups(int $project_id): array {
        $sql = <<<SQL
            SELECT b.app_id, b.status, b.database_bytes, b.files_bytes, b.expires_at,
                a.label, a.slug, a.environment
            FROM app_backup b
            JOIN app a ON a.id = b.app_id
            WHERE a.project_id = :project_id
              AND a.deleted_at IS NOT NULL
              AND (b.status IN ('backing_up', 'failed') OR (b.status = 'ready' AND b.expires_at > NOW()))
            ORDER BY b.created_at DESC
        SQL;

        return $this->db->query_bind($sql, ['project_id' => $project_id], 'object');
    }

    /**
     * A deleted app's backup that can still be downloaded, in a project the
     * customer is a member of, or false
     */
    function member_app_backup(int $customer_id, int $app_id): object|false {
        $sql = <<<SQL
            SELECT b.app_id, b.object_prefix, a.slug, a.project_id
            FROM app_backup b
            JOIN app a ON a.id = b.app_id
            JOIN project p ON p.id = a.project_id
            JOIN project_member pm ON pm.project_id = p.id
            WHERE b.app_id = :app_id
              AND b.status = 'ready'
              AND b.expires_at > NOW()
              AND a.deleted_at IS NOT NULL
              AND p.deleted_at IS NULL
              AND pm.customer_id = :customer_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['app_id' => $app_id, 'customer_id' => $customer_id], 'object');

        return $rows[0] ?? false;
    }
}
