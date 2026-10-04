<?php

/**
 * Destroy Project Task
 *
 * Deletes a deleted project's resources from its tenant namespace. Queued by
 * Platform_actions::queue_project_delete, which soft-deletes the project row
 * (project.deleted_at) first.
 *
 * Payload: {project_id, team_id}. Works from the payload alone, so the project
 * row may be gone. A row that still exists must belong to the team and be
 * soft-deleted, otherwise nothing is touched.
 *
 * Only objects carrying the project's ownership labels are deleted
 * (Platform_task::delete_owned); anything else under an expected name is
 * reported and left in place, and the task fails. It is safe to re-run after a
 * partial failure; nothing retries it automatically, the admin MCP does
 * (retry_project_cleanup, Platform_actions::requeue_project_destroy).
 *
 * Removed: HTTPRoute, Middleware, Service and Deployment (the app stops
 * serving), the MariaDB Grant and User CRs (cleanupPolicy Delete, so access is
 * revoked), the Database CR, the files PVC and the database Secret. Once all of
 * them are gone, the project's verified custom domain is released.
 *
 * Kept on purpose:
 * - the tenant namespace: other projects of the team live there
 * - the MariaDB database: the Database CR uses cleanupPolicy Skip, so the
 *   data survives deleting the CR (drop it by hand after the backup window)
 * - the uploaded files: the tenant-files StorageClass uses reclaimPolicy
 *   Retain, so the JuiceFS subdirectory survives deleting the PVC
 * - the project row (soft-deleted) with its deployments, logs and slug, so
 *   {slug}.PLATFORM_APPS_DOMAIN is never handed to another project
 * - the built images in PLATFORM_REGISTRY
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $db = $runtime->db();
    $team_id = (int) $payload['team_id'];
    $project_id = (int) $payload['project_id'];

    if ($team_id <= 0 || $project_id <= 0) {
        throw new InvalidArgumentException('destroy_project needs team_id and project_id');
    }

    $row = Platform_task::row($db, 'SELECT team_id, deleted_at FROM project WHERE id = ?', 'i', $project_id);

    if ($row !== null && ((int) $row['team_id'] !== $team_id || $row['deleted_at'] === null)) {
        throw new RuntimeException("Project #{$project_id} is not a deleted project of team #{$team_id}; refusing to destroy it");
    }

    $kube = $runtime->kube();
    $failures = 0;

    foreach (Manifests::app_resources($project_id) as [$api_version, $kind, $name]) {
        try {
            $deleted = Platform_task::delete_owned($kube, $api_version, $kind, $team_id, $project_id, $name);
            $log->info($deleted ? "Deleted {$kind} {$name}" : "{$kind} {$name} was already gone");
        } catch (Throwable $e) {
            $failures++;
            $log->error("Deleting {$kind} {$name} failed: {$e->getMessage()}");
        }
    }

    if ($failures > 0) {
        return 1;
    }

    // The route is gone, so another project may verify the domain now
    $stmt = $db->prepare('UPDATE project SET verified_domain = NULL WHERE id = ? AND deleted_at IS NOT NULL');
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $stmt->close();

    $log->info("Project #{$project_id} removed from " . Manifests::namespace_name($team_id) . '; its database and files are kept');

    return 0;
};
