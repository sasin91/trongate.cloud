<?php

require_once __DIR__ . '/Platform_task.php';
require_once __DIR__ . '/../../live/App_backup.php';
require_once __DIR__ . '/../../live/Object_storage.php';

/**
 * The worker's side of a deleted app's backup (App_backup): starting its Job
 * (tasks/destroy_app.php), and seeing it through once the Job has ended
 * (tasks/check_app_backups.php). Then deleting it from the bucket when its
 * download window has passed.
 *
 * Framework-free.
 */
final class App_backup_run {

    /** HEAD and DELETE requests to the bucket */
    const REQUEST_TIMEOUT_SECONDS = 30;

    /** Lines of the backup pod's log kept as the error of a failed backup */
    const ERROR_LOG_LINES = 20;

    /** How long start() waits for an earlier Job to be removed */
    const JOB_GONE_SECONDS = 60;

    /**
     * Start (or start again) the backup Job of a deleted app, with fresh
     * upload links to a new folder in the bucket
     *
     * @param array $app      app row (id, namespace)
     * @param array $database its database server row
     */
    static function start(Runtime $runtime, Task_log $log, Object_storage $storage, array $app, array $database): void {
        $db = $runtime->db();
        $kube = $runtime->kube();
        $app_id = (int) $app['id'];
        $namespace = Manifests::namespace_name($app);
        $job_name = App_backup::job_name($app_id);

        // A Job cannot be changed once created: remove the one a failed backup left
        if (Platform_task::delete_owned($kube, 'batch/v1', 'Job', $namespace, $app_id, $job_name)) {
            $log->info("Deleted the earlier backup Job {$job_name}");
            self::wait_until_job_gone($kube, $namespace, $job_name);
        }

        $prefix = App_backup::new_prefix($app_id);
        $upload_links = [];

        foreach (array_keys(App_backup::PARTS) as $part) {
            $upload_links[$part] = $storage->link('PUT', App_backup::key($prefix, $part), App_backup::UPLOAD_LINK_SECONDS);
        }

        Platform_task::execute(
            $db,
            "INSERT INTO app_backup (app_id, status, object_prefix) VALUES (?, 'backing_up', ?)
             ON DUPLICATE KEY UPDATE status = 'backing_up', object_prefix = VALUES(object_prefix),
                 database_bytes = NULL, files_bytes = NULL, error = NULL, ready_at = NULL, expires_at = NULL",
            [$app_id, $prefix]
        );

        $kube->apply(Manifests::backup_job($app, $database, $upload_links));
        $log->info("Started backup Job {$job_name}: it dumps the database and packs the files, uploads both, then removes them");
    }

    /** A deleted Job lingers while Kubernetes removes it; applying one under its name before then would fail */
    static function wait_until_job_gone(Kubernetes $kube, string $namespace, string $job_name): void {
        $until = time() + self::JOB_GONE_SECONDS;

        while (time() < $until) {
            if ($kube->get('batch/v1', 'Job', $namespace, $job_name) === null) {
                return;
            }

            sleep(1);
        }

        throw new RuntimeException("The earlier backup Job {$job_name} is still being removed; run destroy_app again shortly");
    }

    /**
     * How the backup Job of an app is doing
     *
     * @return string 'running', 'succeeded', 'failed', or 'missing' when there is no Job
     */
    static function job_state(Kubernetes $kube, string $namespace, int $app_id): string {
        return self::job_state_of($kube, $namespace, App_backup::job_name($app_id));
    }

    /**
     * How the Job $job_name is doing (also used for Daily_backup's Jobs)
     *
     * @return string 'running', 'succeeded', 'failed', or 'missing' when there is no Job
     */
    static function job_state_of(Kubernetes $kube, string $namespace, string $job_name): string {
        $job = $kube->get('batch/v1', 'Job', $namespace, $job_name);

        if ($job === null) {
            return 'missing';
        }

        $status = $job['status'] ?? [];

        if ((int) ($status['succeeded'] ?? 0) > 0) {
            return 'succeeded';
        }

        foreach ($status['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'Failed' && ($condition['status'] ?? '') === 'True') {
                return 'failed';
            }
        }

        return 'running';
    }

    /**
     * The backup Job succeeded: record the sizes, open the download window,
     * remove the Job and queue destroy_app to remove the rest of the app
     *
     * @param array $backup app_backup row joined with the app's project_id and namespace
     */
    static function finish(Runtime $runtime, Task_log $log, Object_storage $storage, array $backup): void {
        $db = $runtime->db();
        $app_id = (int) $backup['app_id'];
        $prefix = (string) $backup['object_prefix'];

        $database_bytes = self::object_bytes($storage, App_backup::key($prefix, 'database'));
        $files_bytes = self::object_bytes($storage, App_backup::key($prefix, 'files'));

        Platform_task::execute(
            $db,
            "UPDATE app_backup
             SET status = 'ready', database_bytes = ?, files_bytes = ?, ready_at = NOW(),
                 expires_at = NOW() + INTERVAL ? DAY
             WHERE app_id = ? AND status = 'backing_up'",
            [$database_bytes, $files_bytes, App_backup::KEEP_DAYS, $app_id]
        );

        Platform_task::delete_owned($runtime->kube(), 'batch/v1', 'Job', (string) $backup['namespace'], $app_id, App_backup::job_name($app_id));
        self::queue_destroy($db, (int) $backup['project_id'], $app_id);

        $log->info("App #{$app_id}: backup ready, downloadable for " . App_backup::KEEP_DAYS . ' days; queued destroy_app to remove the rest');
    }

    /**
     * The backup Job failed (or is gone): keep its pod's last log lines as
     * the reason. Its database and files were not touched. The Job stays,
     * for a look at it, until destroy_app is run again.
     *
     * @param array $backup app_backup row joined with the app's namespace
     */
    static function fail(Runtime $runtime, Task_log $log, array $backup): void {
        $app_id = (int) $backup['app_id'];
        $reason = self::job_log_tail($runtime->kube(), (string) $backup['namespace'], $app_id);

        Platform_task::execute(
            $runtime->db(),
            "UPDATE app_backup SET status = 'failed', error = ? WHERE app_id = ? AND status = 'backing_up'",
            [$reason, $app_id]
        );

        $log->error("App #{$app_id}: backup failed, its database and files are kept: {$reason}");
    }

    /**
     * The download window has passed: delete the backup from the bucket
     *
     * @param array $backup app_backup row
     * @return bool whether both objects are gone
     */
    static function expire(Runtime $runtime, Task_log $log, Object_storage $storage, array $backup): bool {
        $app_id = (int) $backup['app_id'];
        $prefix = (string) $backup['object_prefix'];
        $deleted = true;

        foreach (array_keys(App_backup::PARTS) as $part) {
            $status = self::request($storage->link('DELETE', App_backup::key($prefix, $part), 600), 'DELETE');

            // 204 deleted; 404 already gone
            if ($status !== 204 && $status !== 404 && $status !== 200) {
                $deleted = false;
                $log->error("App #{$app_id}: deleting the backup's {$part} failed (HTTP {$status})");
            }
        }

        if (!$deleted) {
            return false;
        }

        Platform_task::execute($runtime->db(), "UPDATE app_backup SET status = 'expired' WHERE app_id = ?", [$app_id]);
        $log->info("App #{$app_id}: backup deleted after " . App_backup::KEEP_DAYS . ' days');

        return true;
    }

    /** Size of an object in the bucket, or null when it cannot be read */
    static function object_bytes(Object_storage $storage, string $key): ?int {
        $curl = curl_init($storage->link('HEAD', $key, 600));
        curl_setopt_array($curl, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $bytes = (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($curl);

        if ($status !== 200 || $bytes < 0) {
            return null;
        }

        return $bytes;
    }

    /** Sends a bodiless request to a signed link; returns the HTTP status (0 when it could not be sent) */
    static function request(string $url, string $method): int {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return $status;
    }

    /** The last lines the backup pod wrote, or why there are none */
    static function job_log_tail(Kubernetes $kube, string $namespace, int $app_id): string {
        $pods = $kube->list('v1', 'Pod', $namespace, 'job-name=' . App_backup::job_name($app_id));

        if ($pods === []) {
            return 'The backup Job is gone or never started a pod (it may have run out of time)';
        }

        $pod_name = (string) $pods[0]['metadata']['name'];
        $tail = trim($kube->pod_log($namespace, $pod_name, self::ERROR_LOG_LINES));

        if ($tail === '') {
            return "The backup pod {$pod_name} wrote nothing; it may have run out of time or memory";
        }

        return $tail;
    }

    /** Queue destroy_app for a deleted app, unless one is already waiting or running */
    static function queue_destroy(PDO $db, int $project_id, int $app_id): void {
        $open = Platform_task::row(
            $db,
            "SELECT id FROM tasks
             WHERE script = 'destroy_app' AND app_id = ? AND completed_at IS NULL AND failed_at IS NULL
             LIMIT 1",
            [$app_id]
        );

        if ($open !== null) {
            return;
        }

        Runtime::run($db, 'INSERT INTO tasks (project_id, app_id, script, payload) VALUES (?, ?, ?, ?)', [
            $project_id,
            $app_id,
            'destroy_app',
            json_encode(['app_id' => $app_id, 'project_id' => $project_id]),
        ]);
    }
}
