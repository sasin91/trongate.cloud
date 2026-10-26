<?php

/**
 * Back Up Daily Task (periodic, Runtime::PERIODIC_TASKS)
 *
 * The nightly backups (Daily_backup), a step at a time on every run:
 *  - app backups whose Job has ended are marked ready or failed;
 *  - backups older than Daily_backup::KEEP_DAYS are deleted from the bucket;
 *  - from Daily_backup::HOUR (UTC): the platform database is backed up once
 *    a day, and app backup Jobs are started for apps without one today,
 *    Daily_backup::RUNNING_AT_ONCE at a time.
 *
 * Off until the backups bucket (APP_BACKUPS_S3_*) and APP_BACKUPS_PUBLIC_KEY
 * are set. Fails (so scheduled_jobs and /metrics show it) when a backup
 * failed or could not be deleted.
 *
 * Payload: {}
 */

require_once __DIR__ . '/lib/Daily_backup_run.php';

return function (Task_log $log) use ($runtime): int {
    $db = $runtime->db();
    $storage = Object_storage::for_app_backups();

    if ($storage === null || APP_BACKUPS_PUBLIC_KEY === '') {
        return 0;
    }

    $now = time();
    $failures = Daily_backup_run::check_running($runtime, $log, $storage);
    $failures += Daily_backup_run::expire($runtime, $log, $storage);

    if (!Daily_backup::due($now)) {
        return $failures > 0 ? 1 : 0;
    }

    if (!Daily_backup_run::platform_done_today($db) && !Daily_backup_run::back_up_platform($runtime, $log, $storage, $now)) {
        $failures++;
    }

    $free = Daily_backup::RUNNING_AT_ONCE - Daily_backup_run::running_count($db);

    foreach (Daily_backup_run::apps_to_back_up($db, $free) as $app) {
        try {
            Daily_backup_run::start_app($runtime, $log, $storage, $app, $now);
        } catch (Throwable $e) {
            $log->error("App #{$app['id']}: could not start its nightly backup: " . $e->getMessage());
            $failures++;
        }
    }

    return $failures > 0 ? 1 : 0;
};
