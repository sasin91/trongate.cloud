<?php

/**
 * Sample Traffic Task
 *
 * Periodic (Runtime::PERIODIC_TASKS, every TRAFFIC_SAMPLE_INTERVAL seconds):
 * reads each Traefik pod's router counters and adds what changed to the apps'
 * 5-minute windows in app_traffic (see App_traffic), then drops windows past
 * retention. Talks to Traefik only, so asleep apps stay asleep.
 *
 * Payload: {} (platform-wide, no project).
 *
 * In scope (see Runtime): $payload, $task, $runtime.
 * Returns a Closure(Task_log): int. The run's summary is saved for /metrics.
 */

require_once APPPATH . 'modules/platform/live/App_traffic.php';

return function (Task_log $log) use ($runtime): int {
    $summary = App_traffic::sample($runtime->db(), $runtime->kube(), time());
    $pruned = App_traffic::prune($runtime->db());

    if ($summary['pods'] === 0 || $summary['failed'] === $summary['pods']) {
        $log->warn("Read no Traefik metrics ({$summary['pods']} pod(s), {$summary['failed']} unreadable)");
    }

    $log->info("Read {$summary['pods']} Traefik pod(s): {$summary['requests']} request(s) to {$summary['apps']} app(s); pruned {$pruned} window(s)");
    $runtime->record_periodic_summary('sample_traffic', $summary);

    return 0;
};
