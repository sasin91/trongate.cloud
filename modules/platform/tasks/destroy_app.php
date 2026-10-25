<?php

/**
 * Destroy App Task
 *
 * Deletes a deleted app's resources from its project's namespace, after
 * backing up its database and files (App_backup). Queued by
 * Platform_actions::queue_app_delete, which soft-deletes the app row
 * (app.deleted_at) first, and again by check_app_backups once the backup
 * is taken.
 *
 * Payload: {app_id, project_id}. Works from the payload and the project's
 * stored namespace, so the app row may be gone. A row that still exists must
 * belong to the project and be soft-deleted, otherwise nothing is touched.
 *
 * Each run:
 *  1. takes the app offline: custom-domain Gateways and their certificate
 *     Secrets, the app's processes, HTTPRoute, Middleware, Service and
 *     Deployment (Manifests::app_serving_objects);
 *  2. sees to its backup. With no backups bucket configured, or an app
 *     that never got a database, there is nothing to back up. Without a
 *     backup yet (or after a failed one) it starts the backup Job and stops
 *     here: check_app_backups queues this task again once the Job is done.
 *     While the Job runs it stops here too, and after a failed backup it
 *     starts a new one;
 *  3. once backed up, removes the rest (Manifests::app_data_objects): the
 *     Job, the MariaDB Grant and User CRs (cleanupPolicy Delete, so access is
 *     revoked), the Database CR, the files PVC and the Secrets, then the
 *     app's Valkey session user. Then the app's verified custom domain is
 *     released.
 *
 * The backup Job drops the database and empties the files volume once both
 * are uploaded. Without a backup (no bucket configured) they are kept, as
 * before: the Database CR uses cleanupPolicy Skip and the tenant-files
 * StorageClass reclaimPolicy Retain.
 *
 * Only objects carrying the app's ownership labels are deleted
 * (Platform_task::delete_owned); anything else under an expected name is
 * reported and left in place, and the task fails. It is safe to re-run after a
 * partial failure; nothing retries it automatically, the admin MCP does
 * (retry_app_cleanup, Platform_actions::requeue_app_destroy).
 *
 * Always kept:
 * - the project's namespace: other apps of the project live there (an empty
 *   one goes with the project, see destroy_project)
 * - the app row (soft-deleted) with its deployments, logs and slug, so
 *   {slug}.PLATFORM_APPS_DOMAIN is never handed to another app
 * - the built images in PLATFORM_REGISTRY
 */

require_once __DIR__ . '/lib/Platform_task.php';
require_once __DIR__ . '/lib/App_backup_run.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $db = $runtime->db();
    $project_id = (int) $payload['project_id'];
    $app_id = (int) $payload['app_id'];

    if ($project_id <= 0 || $app_id <= 0) {
        throw new InvalidArgumentException('destroy_app needs project_id and app_id');
    }

    $row = Platform_task::row($db, 'SELECT project_id, deleted_at FROM app WHERE id = ?', [$app_id]);

    if ($row !== null && ((int) $row['project_id'] !== $project_id || $row['deleted_at'] === null)) {
        throw new RuntimeException("App #{$app_id} is not a deleted app of project #{$project_id}; refusing to destroy it");
    }

    $kube = $runtime->kube();
    $failures = 0;

    $namespace = Platform_task::project_namespace($db, $project_id);

    // Deletes one of the app's objects; 1 when that failed, else 0
    $delete_object = function (string $api_version, string $kind, string $name) use ($kube, $namespace, $app_id, $log): int {
        try {
            $deleted = Platform_task::delete_owned($kube, $api_version, $kind, $namespace, $app_id, $name);
            // A Secret cannot be read, so "not there" and "not ours" look the same
            $gone = $kind === 'Secret' ? "was already gone or does not carry the app's labels (left in place)" : 'was already gone';
            $log->info($deleted ? "Deleted {$kind} {$name}" : "{$kind} {$name} {$gone}");

            return 0;
        } catch (Throwable $e) {
            $log->error("Deleting {$kind} {$name} failed: {$e->getMessage()}");

            return 1;
        }
    };

    // 'done' when the data may go, 'waiting' while the backup runs (or was
    // just started), 'failed' when it could not be started
    $see_to_backup = function () use ($runtime, $db, $log, $app_id, $namespace): string {
        $storage = Object_storage::for_app_backups();

        if ($storage === null) {
            $log->info('No backups bucket is configured (APP_BACKUPS_S3_*): the database and files are kept');

            return 'done';
        }

        $app = Platform_task::row($db, 'SELECT id, database_server_id FROM app WHERE id = ?', [$app_id]);

        if ($app === null || empty($app['database_server_id'])) {
            $log->info('The app was never deployed: there is nothing to back up');

            return 'done';
        }

        $backup = Platform_task::row($db, "SELECT status FROM backup WHERE type = 'app_deleted' AND app_id = ?", [$app_id]);
        $status = $backup['status'] ?? null;

        if ($status === 'ready' || $status === 'expired') {
            return 'done';
        }

        if ($status === 'backing_up') {
            $log->info('The backup is still running; check_app_backups carries on once it is done');

            return 'waiting';
        }

        $database = Platform_task::row($db, 'SELECT * FROM database_server WHERE id = ?', [(int) $app['database_server_id']]);

        if ($database === null) {
            $log->error("Database server #{$app['database_server_id']} no longer exists; cannot back up");

            return 'failed';
        }

        App_backup_run::start($runtime, $log, $storage, ['id' => $app_id, 'namespace' => $namespace], $database);

        return 'waiting';
    };

    // Custom-domain Gateways first (traffic), found by label because their names
    // carry a domain hash; each is ownership-checked like everything else, and
    // its cert-manager certificate Secret (private key included) goes with it.
    try {
        foreach ($kube->list('gateway.networking.k8s.io/v1', 'Gateway', $namespace, Manifests::domain_gateway_selector($app_id)) as $gateway) {
            $gateway_name = (string) $gateway['metadata']['name'];

            try {
                Platform_task::delete_owned($kube, 'gateway.networking.k8s.io/v1', 'Gateway', $namespace, $app_id, $gateway_name);
                Platform_task::delete_domain_tls_secret($kube, $namespace, $gateway_name);
                $log->info("Deleted Gateway {$gateway_name} and its certificate");
            } catch (Throwable $e) {
                $failures++;
                $log->error("Deleting Gateway {$gateway_name} failed: {$e->getMessage()}");
            }
        }
    } catch (Throwable $e) {
        $failures++;
        $log->error("Listing custom-domain Gateways failed: {$e->getMessage()}");
    }

    // The app's processes, found by label because their names come from app_process
    try {
        foreach ($kube->list('apps/v1', 'Deployment', $namespace, Manifests::process_selector($app_id)) as $process) {
            $process_name = (string) $process['metadata']['name'];

            try {
                Platform_task::delete_owned($kube, 'apps/v1', 'Deployment', $namespace, $app_id, $process_name);
                $log->info("Deleted process {$process_name}");
            } catch (Throwable $e) {
                $failures++;
                $log->error("Deleting process {$process_name} failed: {$e->getMessage()}");
            }
        }
    } catch (Throwable $e) {
        $failures++;
        $log->error("Listing the app's processes failed: {$e->getMessage()}");
    }

    foreach (Manifests::app_serving_objects($app_id) as [$api_version, $kind, $name]) {
        $failures += $delete_object($api_version, $kind, $name);
    }

    if ($failures > 0) {
        return 1;
    }

    // The app is offline. Its data goes once it is backed up.
    $backup_state = $see_to_backup();

    if ($backup_state !== 'done') {
        return $backup_state === 'failed' ? 1 : 0;
    }

    foreach (Manifests::app_data_objects($app_id) as [$api_version, $kind, $name]) {
        $failures += $delete_object($api_version, $kind, $name);
    }

    // The app has stopped serving, so its session store can go
    try {
        if (Platform_task::delete_session_user($runtime, $app_id)) {
            $log->info('Deleted Valkey user ' . Manifests::session_user($app_id));
        }
    } catch (Throwable $e) {
        $failures++;
        $log->error("Deleting Valkey user failed: {$e->getMessage()}");
    }

    if ($failures > 0) {
        return 1;
    }

    // The route is gone, so another app may verify the domain now
    Platform_task::execute($db, 'UPDATE app SET verified_domain = NULL WHERE id = ? AND deleted_at IS NOT NULL', [$app_id]);

    $log->info("App #{$app_id} removed from {$namespace}");

    return 0;
};
