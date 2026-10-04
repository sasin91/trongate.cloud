<?php

/**
 * Destroy Project Task
 *
 * Deletes a project's resources from its tenant namespace. The namespace
 * itself stays (other projects of the team live there).
 *
 * Payload: {project_id, team_id}. Works from the payload alone, so the
 * project row may be deleted once this task has completed.
 *
 * Kept on purpose:
 * - the MariaDB database: the Database CR uses cleanupPolicy Skip, so the
 *   data survives deleting the CR (drop it by hand after the backup window)
 * - the uploaded files: the tenant-files StorageClass uses reclaimPolicy
 *   Retain, so the JuiceFS subdirectory survives deleting the PVC
 * The User and Grant CRs use cleanupPolicy Delete, so access is revoked.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $team_id = (int) $payload['team_id'];
    $project_id = (int) $payload['project_id'];

    if ($team_id <= 0 || $project_id <= 0) {
        throw new InvalidArgumentException('destroy_project needs team_id and project_id');
    }

    $kube = $runtime->kube();
    $namespace = Manifests::namespace_name($team_id);
    $name = Manifests::app_name($project_id);

    // Traffic first, then workloads, then data access, then config
    $resources = [
        ['gateway.networking.k8s.io/v1', 'HTTPRoute', $name],
        ['traefik.io/v1alpha1', 'Middleware', $name],
        ['v1', 'Service', $name],
        ['apps/v1', 'Deployment', $name],
        ['k8s.mariadb.com/v1alpha1', 'Grant', "{$name}-db-grant"],
        ['k8s.mariadb.com/v1alpha1', 'User', "{$name}-db-user"],
        ['k8s.mariadb.com/v1alpha1', 'Database', "{$name}-db"],
        ['v1', 'PersistentVolumeClaim', "p{$project_id}-files"],
        ['v1', 'Secret', "p{$project_id}-database"],
    ];

    // Custom-domain Gateways (Manifests::domain_gateway) carry the domain in a hash, so find them by label
    try {
        foreach ($kube->list('gateway.networking.k8s.io/v1', 'Gateway', $namespace, Manifests::domain_gateway_selector($project_id)) as $gateway) {
            array_unshift($resources, ['gateway.networking.k8s.io/v1', 'Gateway', (string) $gateway['metadata']['name']]);
        }
    } catch (Throwable $e) {
        $log->error("Listing custom-domain Gateways failed: {$e->getMessage()}");
        return 1;
    }

    $failures = 0;

    foreach ($resources as [$api_version, $kind, $resource]) {
        try {
            $deleted = $kube->delete($api_version, $kind, $namespace, $resource);
            $log->info($deleted ? "Deleted {$kind} {$resource}" : "{$kind} {$resource} was already gone");
        } catch (Throwable $e) {
            $failures++;
            $log->error("Deleting {$kind} {$resource} failed: {$e->getMessage()}");
        }
    }

    if ($failures > 0) {
        return 1;
    }

    $log->info("Project #{$project_id} removed from {$namespace}");

    return 0;
};
