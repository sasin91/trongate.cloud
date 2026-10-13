<?php

/**
 * Check SQL Imports Task (periodic, Runtime::PERIODIC_TASKS)
 *
 * Looks after the Database tab's .sql file imports (Sql_import):
 *  - queues import_sql again for an import that is checking or running but
 *    has no task left to carry it on (the worker was killed mid-task);
 *  - deletes the stored file of uploads never finished, and of imports
 *    that failed or were stopped, once Sql_import::KEEP_HOURS have passed.
 *
 * Payload: {}
 */

require_once __DIR__ . '/lib/Platform_task.php';
require_once __DIR__ . '/../live/Sql_import.php';

return function (Task_log $log) use ($runtime): int {
    $db = $runtime->db();

    $stuck = Platform_task::rows(
        $db,
        "SELECT i.id, i.app_id, a.project_id
         FROM app_sql_import i
         JOIN app a ON a.id = i.app_id
         WHERE i.status IN ('checking', 'running')
           AND i.updated_at < NOW() - INTERVAL ? SECOND
           AND NOT EXISTS (
               SELECT 1 FROM tasks t
               WHERE t.script = 'import_sql'
                 AND t.completed_at IS NULL
                 AND t.failed_at IS NULL
                 AND JSON_VALUE(t.payload, '$.import_id') = i.id
           )",
        [Sql_import::STUCK_SECONDS],
    );

    foreach ($stuck as $import) {
        Runtime::run($db, 'INSERT INTO tasks (project_id, app_id, script, payload) VALUES (?, ?, ?, ?)', [
            (int) $import['project_id'],
            (int) $import['app_id'],
            'import_sql',
            json_encode(['import_id' => (int) $import['id'], 'app_id' => (int) $import['app_id'], 'project_id' => (int) $import['project_id']]),
        ]);
        Platform_task::execute($db, 'UPDATE app_sql_import SET updated_at = NOW() WHERE id = ?', [(int) $import['id']]);
        $log->info("Queued import #{$import['id']} again: its task had died");
    }

    $expired = Platform_task::rows(
        $db,
        "SELECT id FROM app_sql_import
         WHERE status IN ('uploading', 'checked', 'failed', 'stopped')
           AND updated_at < NOW() - INTERVAL ? HOUR
           AND EXISTS (SELECT 1 FROM app_sql_import_chunk c WHERE c.import_id = app_sql_import.id)",
        [Sql_import::KEEP_HOURS],
    );

    foreach ($expired as $import) {
        Platform_task::execute($db, 'DELETE FROM app_sql_import_chunk WHERE import_id = ?', [(int) $import['id']]);
        Platform_task::execute($db, "UPDATE app_sql_import SET status = IF(status = 'uploading', 'stopped', status), file_deleted = 1 WHERE id = ?", [(int) $import['id']]);
        $log->info("Deleted the stored file of import #{$import['id']}");
    }

    return 0;
};
