<?php

/**
 * Rollback Task
 *
 * Re-applies a project's app manifests with an earlier, already-built image.
 * The rollback deployment row (trigger rollback) already carries that image.
 *
 * Payload: {deployment_id, project_id, team_id, target_deployment_id, image}
 *
 * On success the rollback deployment becomes current and the deployment it
 * replaced is marked rolled_back (it can still be rolled back to later).
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $db = $runtime->db();
    $team_id = (int) $payload['team_id'];
    $project_id = (int) $payload['project_id'];
    $deployment_id = (int) $payload['deployment_id'];

    $project = Platform_task::project($db, $team_id, $project_id);
    $deployment = Platform_task::deployment($db, $project_id, $deployment_id);
    $previous_id = (int) ($project['current_deployment_id'] ?? 0);

    $image = (string) ($deployment['image'] ?: ($payload['image'] ?? ''));

    if ($image === '') {
        throw new RuntimeException('Rollback deployment has no image');
    }

    $target_id = (int) ($payload['target_deployment_id'] ?? 0);
    $log->info("Rolling back to deployment #{$target_id} ({$image})");

    $runtime->set_deployment_status($deployment_id, 'deploying', ['image' => $image]);
    $deployment['image'] = $image;

    $database = Platform_task::database_server($db, $project, $log);
    Platform_task::apply($runtime, $log, $project, $deployment, $database);

    if (!Platform_task::wait_for_rollout($runtime, $log, $project)) {
        return 1;
    }

    $runtime->set_deployment_status($deployment_id, 'success');

    if ($previous_id > 0 && $previous_id !== $deployment_id) {
        $previous = Platform_task::row(
            $db,
            'SELECT status, completed_at FROM deployment WHERE id = ? AND project_id = ?',
            'ii',
            $previous_id,
            $project_id
        );

        if ($previous !== null && $previous['status'] === 'success') {
            // Keep its original completion time
            $runtime->set_deployment_status($previous_id, 'rolled_back', ['completed_at' => $previous['completed_at']]);
            $log->info("Deployment #{$previous_id} marked rolled back");
        }
    }

    $log->info('Live at ' . Trongate_config::base_url($project));

    return 0;
};
