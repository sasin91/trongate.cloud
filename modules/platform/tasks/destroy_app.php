<?php

/**
 * Destroy App Task
 *
 * Deletes a deleted app's resources from its tenant namespace. Queued by
 * Platform_actions::queue_app_delete, which soft-deletes the app row
 * (app.deleted_at) first.
 *
 * Payload: {app_id, project_id}. Works from the payload alone, so the app
 * row may be gone. A row that still exists must belong to the project and be
 * soft-deleted, otherwise nothing is touched.
 *
 * Only objects carrying the app's ownership labels are deleted
 * (Platform_task::delete_owned); anything else under an expected name is
 * reported and left in place, and the task fails. It is safe to re-run after a
 * partial failure; nothing retries it automatically, the admin MCP does
 * (retry_app_cleanup, Platform_actions::requeue_app_destroy).
 *
 * Removed: custom-domain Gateways and their certificate Secrets, HTTPRoute,
 * Middleware, Service and Deployment (the app stops
 * serving), the MariaDB Grant and User CRs (cleanupPolicy Delete, so access is
 * revoked), the Database CR, the files PVC and the database Secret. Once all of
 * them are gone, the app's verified custom domain is released.
 *
 * Kept on purpose:
 * - the tenant namespace: other apps of the project live there
 * - the MariaDB database: the Database CR uses cleanupPolicy Skip, so the
 *   data survives deleting the CR (drop it by hand after the backup window)
 * - the uploaded files: the tenant-files StorageClass uses reclaimPolicy
 *   Retain, so the JuiceFS subdirectory survives deleting the PVC
 * - the app row (soft-deleted) with its deployments, logs and slug, so
 *   {slug}.PLATFORM_APPS_DOMAIN is never handed to another app
 * - the built images in PLATFORM_REGISTRY
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $db = $runtime->db();
    $project_id = (int) $payload['project_id'];
    $app_id = (int) $payload['app_id'];

    if ($project_id <= 0 || $app_id <= 0) {
        throw new InvalidArgumentException('destroy_app needs project_id and app_id');
    }

    $row = Platform_task::row($db, 'SELECT project_id, deleted_at FROM app WHERE id = ?', 'i', $app_id);

    if ($row !== null && ((int) $row['project_id'] !== $project_id || $row['deleted_at'] === null)) {
        throw new RuntimeException("App #{$app_id} is not a deleted app of project #{$project_id}; refusing to destroy it");
    }

    $kube = $runtime->kube();
    $failures = 0;

    $namespace = Manifests::namespace_name($project_id);

    // Custom-domain Gateways first (traffic), found by label because their names
    // carry a domain hash; each is ownership-checked like everything else, and
    // its cert-manager certificate Secret (private key included) goes with it.
    try {
        foreach ($kube->list('gateway.networking.k8s.io/v1', 'Gateway', $namespace, Manifests::domain_gateway_selector($app_id)) as $gateway) {
            $gateway_name = (string) $gateway['metadata']['name'];

            try {
                Platform_task::delete_owned($kube, 'gateway.networking.k8s.io/v1', 'Gateway', $project_id, $app_id, $gateway_name);
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

    foreach (Manifests::app_objects($app_id) as [$api_version, $kind, $name]) {
        try {
            $deleted = Platform_task::delete_owned($kube, $api_version, $kind, $project_id, $app_id, $name);
            // A Secret cannot be read, so "not there" and "not ours" look the same
            $gone = $kind === 'Secret' ? "was already gone or does not carry the app's labels (left in place)" : 'was already gone';
            $log->info($deleted ? "Deleted {$kind} {$name}" : "{$kind} {$name} {$gone}");
        } catch (Throwable $e) {
            $failures++;
            $log->error("Deleting {$kind} {$name} failed: {$e->getMessage()}");
        }
    }

    if ($failures > 0) {
        return 1;
    }

    // The route is gone, so another app may verify the domain now
    $stmt = $db->prepare('UPDATE app SET verified_domain = NULL WHERE id = ? AND deleted_at IS NOT NULL');
    $stmt->bind_param('i', $app_id);
    $stmt->execute();
    $stmt->close();

    $log->info("App #{$app_id} removed from " . Manifests::namespace_name($project_id) . '; its database and files are kept');

    return 0;
};
