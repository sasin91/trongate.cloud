<?php

require_once __DIR__ . '/Platform_task.php';
require_once __DIR__ . '/App_backup_run.php';
require_once __DIR__ . '/../../live/Daily_backup.php';
require_once __DIR__ . '/../../live/Database_dump.php';
require_once __DIR__ . '/../../live/Backup_file.php';
require_once __DIR__ . '/../../live/Object_storage.php';

/**
 * The worker's side of the nightly backups (Daily_backup), run by
 * tasks/back_up_daily.php: starting each app's backup Job and seeing it
 * through, backing up the platform database, and deleting backups once they
 * are older than Daily_backup::KEEP_DAYS.
 *
 * Framework-free.
 */
final class Daily_backup_run {

    /** Where the worker writes the platform database's dump before uploading it */
    const WORK_DIR = '/tmp/tc-daily-backup';

    /**
     * The start of today (UTC) in the database's own time zone, for
     * "created today" checks against TIMESTAMP columns
     */
    const TODAY_SQL = 'NOW() - INTERVAL TIME_TO_SEC(UTC_TIME()) SECOND';

    /**
     * Running apps without a backup today: deployed at least once (so they have
     * a database and a files volume), not deleted, in a project not deleted
     *
     * @return list<array> app rows with the project's namespace and the database server's host and port
     */
    static function apps_to_back_up(PDO $db, int $limit): array {
        if ($limit < 1) {
            return [];
        }

        return Platform_task::rows(
            $db,
            'SELECT a.id, a.project_id, p.namespace, ds.host, ds.port
             FROM app a
             JOIN project p ON p.id = a.project_id
             JOIN database_server ds ON ds.id = a.database_server_id
             WHERE a.deleted_at IS NULL
               AND p.deleted_at IS NULL
               AND a.current_deployment_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM daily_backup b
                   WHERE b.app_id = a.id AND b.created_at >= ' . self::TODAY_SQL . '
               )
             ORDER BY a.id
             LIMIT ?',
            [$limit]
        );
    }

    /** App backups whose Job is running */
    static function running_count(PDO $db): int {
        $row = Platform_task::row($db, "SELECT COUNT(*) AS n FROM daily_backup WHERE source = 'app' AND status = 'backing_up'");

        return (int) ($row['n'] ?? 0);
    }

    /**
     * Start an app's backup Job, with upload links to a new folder
     *
     * @param array $app app row with namespace, host and port (apps_to_back_up())
     */
    static function start_app(Runtime $runtime, Task_log $log, Object_storage $storage, array $app, int $now): void {
        $db = $runtime->db();
        $kube = $runtime->kube();
        $app_id = (int) $app['id'];
        $prefix = Daily_backup::new_app_prefix($app_id, $now);

        // First, so an app whose Job cannot be started is tried once a night, not on every run
        Platform_task::execute(
            $db,
            "INSERT INTO daily_backup (source, app_id, status, object_prefix, expires_at)
             VALUES ('app', ?, 'backing_up', ?, NOW() + INTERVAL ? DAY)",
            [$app_id, $prefix, Daily_backup::KEEP_DAYS]
        );

        $namespace = Manifests::namespace_name($app);
        $job_name = Daily_backup::job_name($app_id);

        // Normally removed when it ended; one left over (a worker restart) would block a new one
        if (Platform_task::delete_owned($kube, 'batch/v1', 'Job', $namespace, $app_id, $job_name)) {
            $log->info("Deleted the earlier Job {$job_name}");
            App_backup_run::wait_until_job_gone($kube, $namespace, $job_name);
        }

        $upload_links = [];

        foreach (array_keys(Daily_backup::PARTS) as $part) {
            $upload_links[$part] = $storage->link('PUT', Daily_backup::key($prefix, 'app', $part), Daily_backup::UPLOAD_LINK_SECONDS, [], $now);
        }

        $database = ['host' => $app['host'], 'port' => $app['port']];
        $kube->apply(Manifests::daily_backup_job($app, $database, $upload_links, APP_BACKUPS_PUBLIC_KEY));
        $log->info("App #{$app_id}: started {$job_name}");
    }

    /**
     * See the app backups whose Job has ended through: ready with their sizes,
     * or failed with the pod's last lines. The Job is removed either way.
     * Backups started in the last minute are left alone, so a Job still being
     * created is not taken for a missing one.
     *
     * @return int how many failed
     */
    static function check_running(Runtime $runtime, Task_log $log, Object_storage $storage): int {
        $db = $runtime->db();
        $failed = 0;

        // LEFT JOIN: a backup whose app or project is gone fails (no namespace) instead of holding a slot
        $running = Platform_task::rows(
            $db,
            "SELECT b.id, b.app_id, b.object_prefix, p.namespace
             FROM daily_backup b
             LEFT JOIN app a ON a.id = b.app_id
             LEFT JOIN project p ON p.id = a.project_id
             WHERE b.source = 'app' AND b.status = 'backing_up'
               AND b.created_at < NOW() - INTERVAL 1 MINUTE",
        );

        foreach ($running as $backup) {
            try {
                $failed += self::check_app($runtime, $log, $storage, $backup);
            } catch (Throwable $e) {
                Platform_task::execute($db, "UPDATE daily_backup SET status = 'failed', error = ? WHERE id = ?", [$e->getMessage(), (int) $backup['id']]);
                $log->error("App #{$backup['app_id']}: nightly backup failed: " . $e->getMessage());
                $failed++;
            }
        }

        return $failed;
    }

    /**
     * One running app backup: nothing while its Job runs, else ready or failed
     *
     * @param array $backup daily_backup row with the app's namespace
     * @return int 1 when it failed
     */
    static function check_app(Runtime $runtime, Task_log $log, Object_storage $storage, array $backup): int {
        $db = $runtime->db();
        $kube = $runtime->kube();
        $app_id = (int) $backup['app_id'];
        $namespace = Manifests::namespace_name($backup);
        $job_name = Daily_backup::job_name($app_id);
        $state = App_backup_run::job_state_of($kube, $namespace, $job_name);

        if ($state === 'running') {
            return 0;
        }

        $failed = 0;

        if ($state === 'succeeded') {
            $prefix = (string) $backup['object_prefix'];
            Platform_task::execute(
                $db,
                "UPDATE daily_backup SET status = 'ready', database_bytes = ?, files_bytes = ?, ready_at = NOW() WHERE id = ?",
                [
                    App_backup_run::object_bytes($storage, Daily_backup::key($prefix, 'app', 'database')),
                    App_backup_run::object_bytes($storage, Daily_backup::key($prefix, 'app', 'files')),
                    (int) $backup['id'],
                ]
            );
            $log->info("App #{$app_id}: nightly backup ready");
        } else {
            $reason = App_backup_run::job_log_tail_of($kube, $namespace, $job_name);
            Platform_task::execute($db, "UPDATE daily_backup SET status = 'failed', error = ? WHERE id = ?", [$reason, (int) $backup['id']]);
            $log->error("App #{$app_id}: nightly backup failed: {$reason}");
            $failed = 1;
        }

        Platform_task::delete_owned($kube, 'batch/v1', 'Job', $namespace, $app_id, $job_name);

        return $failed;
    }

    /** Whether the platform database has been backed up (or tried) today */
    static function platform_done_today(PDO $db): bool {
        return Platform_task::row(
            $db,
            "SELECT id FROM daily_backup WHERE source = 'platform' AND created_at >= " . self::TODAY_SQL . ' LIMIT 1'
        ) !== null;
    }

    /**
     * Dump the platform database (this worker's own connection settings),
     * encrypt it and upload it
     *
     * @return bool whether it worked
     */
    static function back_up_platform(Runtime $runtime, Task_log $log, Object_storage $storage, int $now): bool {
        $db = $runtime->db();
        $prefix = Daily_backup::new_platform_prefix($now);

        Platform_task::execute(
            $db,
            "INSERT INTO daily_backup (source, app_id, status, object_prefix, expires_at)
             VALUES ('platform', NULL, 'backing_up', ?, NOW() + INTERVAL ? DAY)",
            [$prefix, Daily_backup::KEEP_DAYS]
        );
        $id = (int) $db->lastInsertId();

        $plain = self::WORK_DIR . '/platform.sql.gz';
        $encrypted = $plain . '.enc';

        try {
            if (!is_dir(self::WORK_DIR) && !mkdir(self::WORK_DIR, 0700, true)) {
                throw new RuntimeException('Cannot create ' . self::WORK_DIR);
            }

            $database = getenv('DB_NAME') ?: 'tcloud';
            $pdo = Database_dump::connect(
                getenv('DB_HOST') ?: '127.0.0.1',
                (int) (getenv('DB_PORT') ?: 3306),
                $database,
                getenv('DB_USER') ?: 'root',
                getenv('DB_PASSWORD') ?: ''
            );
            Database_dump::write($pdo, $database, $plain, fn(string $line) => null);
            $pdo = null;

            Backup_file::encrypt($plain, $encrypted, APP_BACKUPS_PUBLIC_KEY);
            unlink($plain);

            $link = $storage->link('PUT', Daily_backup::key($prefix, 'platform', 'database'), Daily_backup::UPLOAD_LINK_SECONDS);
            $bytes = Object_storage::upload($encrypted, $link);

            Platform_task::execute($db, "UPDATE daily_backup SET status = 'ready', database_bytes = ?, ready_at = NOW() WHERE id = ?", [$bytes, $id]);
            $log->info("Platform database backed up ({$bytes} bytes encrypted)");

            return true;
        } catch (Throwable $e) {
            Platform_task::execute($db, "UPDATE daily_backup SET status = 'failed', error = ? WHERE id = ?", [$e->getMessage(), $id]);
            $log->error('Platform database backup failed: ' . $e->getMessage());

            return false;
        } finally {
            foreach ([$plain, $encrypted] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * Delete backups past Daily_backup::KEEP_DAYS from the bucket: failed ones
     * too (a part may have been uploaded), and ones whose run never ended
     *
     * @return int how many could not be deleted
     */
    static function expire(Runtime $runtime, Task_log $log, Object_storage $storage): int {
        $db = $runtime->db();
        $failures = 0;

        $expired = Platform_task::rows(
            $db,
            "SELECT id, source, app_id, object_prefix FROM daily_backup
             WHERE status IN ('ready', 'failed', 'backing_up') AND expires_at < NOW()",
        );

        foreach ($expired as $backup) {
            $source = (string) $backup['source'];
            $parts = $source === 'platform' ? Daily_backup::PLATFORM_PARTS : Daily_backup::PARTS;
            $deleted = true;

            foreach (array_keys($parts) as $part) {
                $key = Daily_backup::key((string) $backup['object_prefix'], $source, $part);
                $status = App_backup_run::request($storage->link('DELETE', $key, 600), 'DELETE');

                // 204 deleted; 404 already gone (or never uploaded)
                if ($status !== 204 && $status !== 404 && $status !== 200) {
                    $deleted = false;
                    $log->error("Nightly backup #{$backup['id']}: deleting its {$part} failed (HTTP {$status})");
                }
            }

            if (!$deleted) {
                $failures++;
                continue;
            }

            Platform_task::execute($db, "UPDATE daily_backup SET status = 'expired' WHERE id = ?", [(int) $backup['id']]);
        }

        if ($expired !== []) {
            $log->info('Deleted ' . (count($expired) - $failures) . ' nightly backups older than ' . Daily_backup::KEEP_DAYS . ' days');
        }

        return $failures;
    }
}
