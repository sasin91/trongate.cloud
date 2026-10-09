<?php

/**
 * Redeploy Outdated Apps Task
 *
 * Periodic (Runtime::PERIODIC_TASKS, every APP_CONFIG_REDEPLOY_INTERVAL
 * seconds): the platform writes its values into an app's config/*.php only
 * when the app is applied (Trongate_config::rules), so after a release that
 * changes them (Trongate_config::CONFIG_VERSION) running apps keep the old
 * config until someone deploys. This queues a deploy of each such app's
 * current commit (trigger 'platform'), so it picks the new config up on its
 * own. A deploy rather than a re-apply: deployments from before
 * trongate.cloud kept their config files cannot be applied again.
 *
 * Only apps whose current deployment succeeded and nothing newer was tried
 * since: a failed or pending deploy is for a human (or that deploy) to sort
 * out, so a redeploy that fails is never queued again. At most MAX_PER_RUN
 * per run, and it stops when a customer task is waiting, so a release never
 * holds up customer deploys; the rest go next run.
 *
 * Payload: {} (platform-wide, no project).
 *
 * In scope (see Runtime): $payload, $task, $runtime.
 * Returns a Closure(Task_log): int. The run's summary is saved for /metrics.
 */

require_once __DIR__ . '/lib/Platform_task.php';
require_once __DIR__ . '/../worker/Worker_actions.php';

return function (Task_log $log) use ($runtime): int {
    $max_per_run = 3;

    $apps = Platform_task::rows($runtime->db(), <<<SQL
        SELECT a.id, a.project_id, d.id AS deployment_id, d.commit_sha, d.config_version
        FROM app a
        JOIN project p ON p.id = a.project_id
        JOIN deployment d ON d.id = a.current_deployment_id
        WHERE a.deleted_at IS NULL
          AND p.deleted_at IS NULL
          AND a.source_url <> ''
          AND d.status = 'success'
          AND d.commit_sha IS NOT NULL
          AND (d.config_version IS NULL OR d.config_version < ?)
          AND NOT EXISTS (SELECT 1 FROM deployment n WHERE n.app_id = a.id AND n.id > d.id)
        ORDER BY a.id
        LIMIT ?
        SQL, [Trongate_config::CONFIG_VERSION, $max_per_run]);

    $actions = new Worker_actions($runtime->db());
    $summary = ['outdated' => count($apps), 'queued' => 0, 'failed' => 0];

    foreach ($apps as $app) {
        if (Platform_task::project_task_waiting($runtime->db())) {
            $log->info('A customer task is waiting; stopping until the next run');
            break;
        }

        try {
            $deployment_id = $actions->queue_deploy((int) $app['project_id'], (int) $app['id'], null, 'platform', (string) $app['commit_sha']);
            $summary['queued']++;
            $log->info(sprintf(
                'App #%d: deployment #%d has platform config version %s, now %d; queued deployment #%d of %s',
                $app['id'],
                $app['deployment_id'],
                $app['config_version'] ?? 'unrecorded',
                Trongate_config::CONFIG_VERSION,
                $deployment_id,
                substr((string) $app['commit_sha'], 0, 7)
            ));
        } catch (Throwable $e) {
            $summary['failed']++;
            $log->error("App #{$app['id']}: queueing a redeploy failed: {$e->getMessage()}");
        }
    }

    $runtime->record_periodic_summary('redeploy_outdated_apps', $summary);

    return 0;
};
