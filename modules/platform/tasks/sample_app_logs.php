<?php

/**
 * Sample App Logs Task
 *
 * Periodic (Runtime::PERIODIC_TASKS, every APP_LOG_SAMPLE_INTERVAL seconds):
 * adds each live app's new log lines, by level, to its 5-minute windows in
 * app_log_rate (see App_log_rates), then drops windows past retention.
 * Asleep apps have no pods to read and are not woken. Stops early when a
 * customer task is waiting; the cursors pick up where it left off next run.
 *
 * Payload: {} (platform-wide, no project).
 *
 * In scope (see Runtime): $payload, $task, $runtime.
 * Returns a Closure(Task_log): int. The run's summary is saved for /metrics.
 */

require_once __DIR__ . '/lib/Platform_task.php';
require_once APPPATH . 'modules/platform/live/App_log_rates.php';

return function (Task_log $log) use ($runtime): int {
    $apps = Platform_task::rows($runtime->db(), <<<SQL
        SELECT a.id, p.namespace
        FROM app a
        JOIN project p ON p.id = a.project_id
        WHERE a.current_deployment_id IS NOT NULL
          AND a.deleted_at IS NULL
          AND p.deleted_at IS NULL
        ORDER BY a.id
        SQL);

    $summary = ['apps' => 0, 'lines' => 0, 'warnings' => 0, 'errors' => 0, 'failed' => 0];

    foreach ($apps as $app) {
        if (Platform_task::project_task_waiting($runtime->db())) {
            $log->info('A customer task is waiting; stopping until the next run');
            break;
        }

        $summary['apps']++;

        try {
            $counts = App_log_rates::sample($runtime->db(), $runtime->kube(), $app);
        } catch (Throwable $e) {
            // One unreadable app (API hiccup, namespace gone) must not stop the others
            $summary['failed']++;
            $log->warn("App #{$app['id']}: {$e->getMessage()}");
            continue;
        }

        $summary['lines'] += $counts['total'];
        $summary['warnings'] += $counts['warnings'];
        $summary['errors'] += $counts['errors'];
    }

    $pruned = App_log_rates::prune($runtime->db());

    $log->info("Sampled {$summary['apps']} app(s): {$summary['lines']} lines, {$summary['warnings']} warnings, {$summary['errors']} errors; pruned {$pruned} window(s)");
    $runtime->record_periodic_summary('sample_app_logs', $summary);

    return 0;
};
