<?php

/**
 * Destroy Service Task
 *
 * Deletes a deleted service's objects (Project_service::objects) from its
 * project's namespace. Queued by Platform_actions::queue_service_delete,
 * which soft-deletes the row (service.deleted_at) and drops its variables
 * first.
 *
 * Payload: {service_id, project_id}. The row must belong to the project and
 * be soft-deleted, otherwise nothing is touched. Only objects carrying the
 * service's ownership labels are deleted; anything else under an expected
 * name is reported and left in place, and the task fails. Safe to re-run.
 *
 * The project's apps keep the service's {NAME}_URL until their next deploy.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $db = $runtime->db();
    $project_id = (int) $payload['project_id'];
    $service_id = (int) $payload['service_id'];

    if ($project_id <= 0 || $service_id <= 0) {
        throw new InvalidArgumentException('destroy_service needs project_id and service_id');
    }

    $row = Platform_task::row($db, 'SELECT project_id, deleted_at FROM service WHERE id = ?', [$service_id]);

    if ($row === null || (int) $row['project_id'] !== $project_id || $row['deleted_at'] === null) {
        throw new RuntimeException("Service #{$service_id} is not a deleted service of project #{$project_id}; refusing to destroy it");
    }

    $namespace = Platform_task::project_namespace($db, $project_id);
    $kube = $runtime->kube();
    $failures = 0;

    foreach (Project_service::objects($service_id) as [$api_version, $kind, $name]) {
        try {
            if ($kind === 'Secret') {
                // A Secret cannot be read: the API server checks the labels
                $selector = implode(',', array_map(
                    fn(string $key, string $value) => "{$key}={$value}",
                    array_keys(Project_service::labels($service_id)),
                    Project_service::labels($service_id)
                ));
                $deleted = $kube->delete_collection($api_version, $kind, $namespace, $selector, 'metadata.name=' . $name) > 0;
                $log->info($deleted ? "Deleted {$kind} {$name}" : "{$kind} {$name} was already gone or does not carry the service's labels (left in place)");
                continue;
            }

            $object = $kube->get($api_version, $kind, $namespace, $name);

            if ($object === null) {
                $log->info("{$kind} {$name} was already gone");
                continue;
            }

            if (!Project_service::owned_by_service($object, $namespace, $service_id)) {
                throw new RuntimeException("{$kind} {$name} does not carry service #{$service_id}'s labels; left in place");
            }

            $uid = $object['metadata']['uid'] ?? null;
            $kube->delete($api_version, $kind, $namespace, $name, is_string($uid) ? $uid : null);
            $log->info("Deleted {$kind} {$name}");
        } catch (Throwable $e) {
            $failures++;
            $log->error("Deleting {$kind} {$name} failed: {$e->getMessage()}");
        }
    }

    if ($failures > 0) {
        return 1;
    }

    $log->info("Service #{$service_id} removed from {$namespace}");

    return 0;
};
