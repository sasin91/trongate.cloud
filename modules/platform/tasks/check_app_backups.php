<?php

/**
 * Check App Backups Task (periodic, Runtime::PERIODIC_TASKS)
 *
 * Looks after the backups of deleted apps (App_backup):
 *  - a backup whose Job succeeded is marked ready, and destroy_app is queued
 *    again to remove the rest of the app;
 *  - a backup whose Job failed or is gone is marked failed, with the pod's
 *    last log lines as the reason (its database and files are kept);
 *  - a ready backup past its download window is deleted from the bucket.
 *
 * Backups started in the last minute are left alone, so a Job that is
 * still being created is not taken for a missing one.
 *
 * Payload: {}
 */

require_once __DIR__ . '/lib/Platform_task.php';
require_once __DIR__ . '/lib/App_backup_run.php';

return function (Task_log $log) use ($runtime): int {
    $db = $runtime->db();
    $storage = Object_storage::for_app_backups();

    if ($storage === null) {
        return 0;
    }

    $sql = <<<SQL
        SELECT b.app_id, b.object_prefix, a.project_id, p.namespace
        FROM backup b
        JOIN app a ON a.id = b.app_id
        JOIN project p ON p.id = a.project_id
        WHERE b.type = 'app_deleted' AND b.status = 'backing_up'
          AND b.started_at < NOW() - INTERVAL 1 MINUTE
    SQL;
    $running = Platform_task::rows($db, $sql);

    foreach ($running as $backup) {
        $backup['namespace'] = Manifests::namespace_name($backup);
        $state = App_backup_run::job_state($runtime->kube(), $backup['namespace'], (int) $backup['app_id']);

        if ($state === 'succeeded') {
            App_backup_run::finish($runtime, $log, $storage, $backup);
        }

        if ($state === 'failed' || $state === 'missing') {
            App_backup_run::fail($runtime, $log, $backup);
        }
    }

    $sql = <<<SQL
        SELECT app_id, object_prefix FROM backup
        WHERE type = 'app_deleted' AND status = 'ready' AND expires_at < NOW()
    SQL;
    $expired = Platform_task::rows($db, $sql);

    $failures = 0;

    foreach ($expired as $backup) {
        if (!App_backup_run::expire($runtime, $log, $storage, $backup)) {
            $failures++;
        }
    }

    return $failures > 0 ? 1 : 0;
};
