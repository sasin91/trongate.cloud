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

    /** The worker's own namespace (deploy/k8s/platform) */
    const WORKER_NAMESPACE = 'trongate-cloud';

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

        $today = self::TODAY_SQL;
        $sql = <<<SQL
            SELECT a.id, a.project_id, p.namespace, ds.host, ds.port
            FROM app a
            JOIN project p ON p.id = a.project_id
            JOIN database_server ds ON ds.id = a.database_server_id
            WHERE a.deleted_at IS NULL
              AND p.deleted_at IS NULL
              AND a.current_deployment_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM backup b
                  WHERE b.type = 'nightly' AND b.app_id = a.id AND b.started_at >= {$today}
              )
            ORDER BY a.id
            LIMIT ?
        SQL;

        return Platform_task::rows($db, $sql, [$limit]);
    }

    /** App backups whose Job is running */
    static function running_count(PDO $db): int {
        $sql = <<<SQL
            SELECT COUNT(*) AS n FROM backup WHERE type = 'nightly' AND app_id IS NOT NULL AND status = 'backing_up'
        SQL;
        $row = Platform_task::row($db, $sql);

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
        $sql = <<<SQL
            INSERT INTO backup (type, app_id, status, object_prefix, expires_at)
            VALUES ('nightly', ?, 'backing_up', ?, NOW() + INTERVAL ? DAY)
        SQL;
        Platform_task::execute($db, $sql, [$app_id, $prefix, Daily_backup::KEEP_DAYS]);

        $namespace = Manifests::namespace_name($app);
        $job_name = Daily_backup::job_name($app_id);

        // Normally removed when it ended; one left over (a worker restart) would block a new one
        if (Platform_task::delete_owned($kube, 'batch/v1', 'Job', $namespace, $app_id, $job_name)) {
            $log->info("Deleted the earlier Job {$job_name}");
            App_backup_run::wait_until_job_gone($kube, $namespace, $job_name);
        }

        $app_deployment = $kube->get('apps/v1', 'Deployment', $namespace, Manifests::app_name($app_id));

        if ($app_deployment === null) {
            throw new RuntimeException("Its Deployment {$namespace}/" . Manifests::app_name($app_id) . ' is missing');
        }

        $link = $storage->link('PUT', Daily_backup::key($prefix, 'app'), Daily_backup::UPLOAD_LINK_SECONDS, [], $now);
        $database = ['host' => $app['host'], 'port' => $app['port']];

        $kube->apply(Manifests::daily_backup_job($app, $database, $app_deployment, self::worker_image($kube), $link, APP_BACKUPS_PUBLIC_KEY));
        $log->info("App #{$app_id}: started {$job_name}");
    }

    /**
     * The image this worker runs, which the backup Jobs run their own steps
     * from: read from its own Pod (HOSTNAME is the Pod's name), so it is
     * always the release that is running
     */
    static function worker_image(Kubernetes $kube): string {
        static $image = null;

        if ($image !== null) {
            return $image;
        }

        $pod = $kube->get('v1', 'Pod', self::WORKER_NAMESPACE, (string) gethostname());
        $found = $pod['spec']['containers'][0]['image'] ?? '';

        if (!is_string($found) || $found === '') {
            throw new RuntimeException('Cannot read the worker\'s own image from its Pod ' . gethostname());
        }

        return $image = $found;
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
        $sql = <<<SQL
            SELECT b.id, b.app_id, b.object_prefix, p.namespace
            FROM backup b
            LEFT JOIN app a ON a.id = b.app_id
            LEFT JOIN project p ON p.id = a.project_id
            WHERE b.type = 'nightly' AND b.app_id IS NOT NULL AND b.status = 'backing_up'
              AND b.started_at < NOW() - INTERVAL 1 MINUTE
        SQL;
        $running = Platform_task::rows($db, $sql);

        foreach ($running as $backup) {
            try {
                $failed += self::check_app($runtime, $log, $storage, $backup);
            } catch (Throwable $e) {
                $sql = <<<SQL
                    UPDATE backup SET status = 'failed', error = ?, finished_at = NOW() WHERE id = ?
                SQL;
                Platform_task::execute($db, $sql, [$e->getMessage(), (int) $backup['id']]);
                $log->error("App #{$backup['app_id']}: nightly backup failed: " . $e->getMessage());
                $failed++;
            }
        }

        return $failed;
    }

    /**
     * One running app backup: nothing while its Job runs, else ready or failed
     *
     * @param array $backup backup row with the app's namespace
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
            $size_bytes = App_backup_run::object_bytes($storage, Daily_backup::key((string) $backup['object_prefix'], 'app'));
            $note = self::module_failure($kube, $namespace, $job_name);

            $sql = <<<SQL
                UPDATE backup SET status = 'ready', size_bytes = ?, error = ?, finished_at = NOW() WHERE id = ?
            SQL;
            Platform_task::execute($db, $sql, [$size_bytes, $note, (int) $backup['id']]);
            $log->info("App #{$app_id}: nightly backup ready" . ($note !== null ? ", but its backup module failed: {$note}" : ''));
        } else {
            $reason = self::failure_reason($kube, $namespace, $job_name);
            $sql = <<<SQL
                UPDATE backup SET status = 'failed', error = ?, finished_at = NOW() WHERE id = ?
            SQL;
            Platform_task::execute($db, $sql, [$reason, (int) $backup['id']]);
            $log->error("App #{$app_id}: nightly backup failed: {$reason}");
            $failed = 1;
        }

        Platform_task::delete_owned($kube, 'batch/v1', 'Job', $namespace, $app_id, $job_name);

        return $failed;
    }

    /**
     * The line Backup_runtime::note_module_failure logs when the app's own backup module
     * failed, or null when it did not (or the app has none)
     */
    static function module_failure(Kubernetes $kube, string $namespace, string $job_name): ?string {
        $pod = self::job_pod($kube, $namespace, $job_name);

        if ($pod === null) {
            return null;
        }

        $log = $kube->pod_log($namespace, (string) $pod['metadata']['name'], App_backup_run::ERROR_LOG_LINES, ['container' => 'upload']);

        foreach (explode("\n", $log) as $line) {
            if (str_starts_with($line, 'runtime.php failed')) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Why a backup Job failed: the step that failed and its last log lines
     * (the Job's pod runs database, app, then upload)
     */
    static function failure_reason(Kubernetes $kube, string $namespace, string $job_name): string {
        $pod = self::job_pod($kube, $namespace, $job_name);

        if ($pod === null) {
            return 'The backup Job is gone or never started a pod (it may have run out of time)';
        }

        $pod_name = (string) $pod['metadata']['name'];
        $statuses = array_merge($pod['status']['initContainerStatuses'] ?? [], $pod['status']['containerStatuses'] ?? []);

        foreach ($statuses as $status) {
            $terminated = $status['state']['terminated'] ?? null;
            $waiting = $status['state']['waiting'] ?? null;
            $step = (string) ($status['name'] ?? '');

            if (is_array($terminated) && (int) ($terminated['exitCode'] ?? 0) !== 0) {
                $tail = trim($kube->pod_log($namespace, $pod_name, App_backup_run::ERROR_LOG_LINES, ['container' => $step]));

                return "The {$step} step failed (exit {$terminated['exitCode']}): " . ($tail !== '' ? $tail : (string) ($terminated['reason'] ?? 'no output'));
            }

            if (is_array($waiting)) {
                return "The {$step} step never started: " . ($waiting['reason'] ?? '') . ' ' . ($waiting['message'] ?? '');
            }
        }

        return "The backup pod {$pod_name} stopped without a failing step; it may have run out of time";
    }

    /** The pod of a backup Job, or null */
    static function job_pod(Kubernetes $kube, string $namespace, string $job_name): ?array {
        $pods = $kube->list('v1', 'Pod', $namespace, 'job-name=' . $job_name);

        return $pods[0] ?? null;
    }

    /** Whether the platform database has been backed up (or tried) today */
    static function platform_done_today(PDO $db): bool {
        $today = self::TODAY_SQL;
        $sql = <<<SQL
            SELECT id FROM backup
            WHERE type = 'nightly' AND app_id IS NULL AND started_at >= {$today}
            LIMIT 1
        SQL;

        return Platform_task::row($db, $sql) !== null;
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

        $sql = <<<SQL
            INSERT INTO backup (type, app_id, status, object_prefix, expires_at)
            VALUES ('nightly', NULL, 'backing_up', ?, NOW() + INTERVAL ? DAY)
        SQL;
        Platform_task::execute($db, $sql, [$prefix, Daily_backup::KEEP_DAYS]);
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

            $link = $storage->link('PUT', Daily_backup::key($prefix, 'platform'), Daily_backup::UPLOAD_LINK_SECONDS);
            $bytes = Object_storage::upload($encrypted, $link);

            $sql = <<<SQL
                UPDATE backup SET status = 'ready', size_bytes = ?, finished_at = NOW() WHERE id = ?
            SQL;
            Platform_task::execute($db, $sql, [$bytes, $id]);
            $log->info("Platform database backed up ({$bytes} bytes encrypted)");

            return true;
        } catch (Throwable $e) {
            $sql = <<<SQL
                UPDATE backup SET status = 'failed', error = ?, finished_at = NOW() WHERE id = ?
            SQL;
            Platform_task::execute($db, $sql, [$e->getMessage(), $id]);
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

        $sql = <<<SQL
            SELECT id, app_id, object_prefix FROM backup
            WHERE type = 'nightly' AND status IN ('ready', 'failed', 'backing_up') AND expires_at < NOW()
        SQL;
        $expired = Platform_task::rows($db, $sql);

        foreach ($expired as $backup) {
            $key = Daily_backup::key((string) $backup['object_prefix'], $backup['app_id'] === null ? 'platform' : 'app');
            $status = App_backup_run::request($storage->link('DELETE', $key, 600), 'DELETE');

            // 204 deleted; 404 already gone (or never uploaded)
            $deleted = in_array($status, [200, 204, 404], true);

            if (!$deleted) {
                $log->error("Nightly backup #{$backup['id']}: deleting it failed (HTTP {$status})");
            }

            if (!$deleted) {
                $failures++;
                continue;
            }

            $sql = <<<SQL
                UPDATE backup SET status = 'expired' WHERE id = ?
            SQL;
            Platform_task::execute($db, $sql, [(int) $backup['id']]);
        }

        if ($expired !== []) {
            $log->info('Deleted ' . (count($expired) - $failures) . ' nightly backups older than ' . Daily_backup::KEEP_DAYS . ' days');
        }

        return $failures;
    }
}
