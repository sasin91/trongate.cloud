<?php

/**
 * Deploy Service Task
 *
 * Applies a project service's manifests (Project_service::manifests: its
 * environment Secret, Deployment, Service and NetworkPolicies) in the
 * project's namespace and waits for it to roll out. Queued by
 * Platform_actions::queue_service_deploy when a service is added or
 * redeployed.
 *
 * Payload: {service_id, project_id}. A service deleted in the meantime is
 * left alone (destroy_service removes it).
 *
 * The project's apps learn its URL ({NAME}_URL) on their own next deploy
 * (Platform_task::service_urls); this task does not redeploy them.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $db = $runtime->db();
    $project_id = (int) $payload['project_id'];
    $service_id = (int) $payload['service_id'];

    if ($project_id <= 0 || $service_id <= 0) {
        throw new InvalidArgumentException('deploy_service needs project_id and service_id');
    }

    $service = Platform_task::row(
        $db,
        'SELECT * FROM service WHERE id = ? AND project_id = ? AND deleted_at IS NULL',
        [$service_id, $project_id]
    );

    if ($service === null) {
        $log->info("Service #{$service_id} was deleted; nothing to deploy");
        return 0;
    }

    $namespace = Platform_task::project_namespace($db, $project_id);
    $kube = $runtime->kube();

    // A project whose apps never deployed has no namespace yet
    $log->info("Applying tenant namespace {$namespace}");
    $kube->apply_all(Manifests::tenant($namespace));

    $env = $runtime->resolve_reference('secrets:' . Project_service::MODULE . ',' . $service_id . ',' . Project_service::SERVICE);
    $env = is_array($env) ? array_map('strval', $env) : [];

    if ($env !== []) {
        $log->info('Environment variables: ' . implode(', ', array_keys($env)));
    }

    $log->info("Image {$service['image']}, port {$service['port']}, {$service['memory_mb']} MB");

    foreach (Project_service::manifests($service, $namespace, $env) as $manifest) {
        // Names and kinds only: the Secret carries the variables
        $log->info("Applying {$manifest['kind']} {$manifest['metadata']['name']}");
        $kube->apply($manifest);
    }

    $name = Project_service::object_name($service_id);
    $done = $kube->wait_for_rollout($namespace, $name, Project_service::ROLLOUT_TIMEOUT_SECONDS, function (string $message) use ($log) {
        $log->info($message);
    });

    if (!$done) {
        $log->error('Service did not become ready within ' . Project_service::ROLLOUT_TIMEOUT_SECONDS . 's');
        Platform_task::log_pod_problems($runtime, $log, $namespace, $name);
        return 1;
    }

    Platform_task::execute($db, 'UPDATE service SET deployed_at = NOW() WHERE id = ?', [$service_id]);

    $variable = Project_service::url_variable((string) $service['name']);
    $log->info("{$service['name']} is running; the project's apps get {$variable} on their next deploy");

    return 0;
};
