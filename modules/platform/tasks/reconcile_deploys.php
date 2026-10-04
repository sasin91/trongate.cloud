<?php

/**
 * Reconcile Deploys Task
 *
 * Periodic (Runtime::PERIODIC_TASKS, every DEPLOY_RECONCILE_INTERVAL seconds):
 * queues a deploy for each auto-deploying app whose branch head was never
 * built because its GitHub push webhook went missing. See Deploy_reconcile.
 *
 * Payload: {} (platform-wide, no project).
 *
 * In scope (see Runtime): $payload, $task, $runtime.
 * Returns a Closure(Task_log): int. The run's summary is saved for /metrics.
 */

require_once __DIR__ . '/lib/Deploy_reconcile.php';

return function (Task_log $log) use ($runtime): int {
    $summary = (new Deploy_reconcile($runtime, $log))->run();

    $runtime->record_periodic_summary('reconcile_deploys', $summary);

    return 0;
};
