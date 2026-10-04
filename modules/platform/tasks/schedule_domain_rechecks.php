<?php

/**
 * Schedule Domain Rechecks Task
 *
 * Periodic (Runtime::PERIODIC_TASKS, every DOMAIN_RECHECK_INTERVAL seconds):
 * queues recheck_domains for each project with a verified custom domain on a
 * live app, skipping a project that still has one waiting. recheck_domains is
 * project-scoped (it re-applies apps in the project's namespace), so the
 * platform-wide schedule only fans out; the checks run as the project's tasks.
 *
 * Payload: {} (platform-wide, no project).
 *
 * In scope (see Runtime): $payload, $task, $runtime.
 * Returns a Closure(Task_log): int. The run's summary is saved for /metrics.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($runtime): int {
    $sql = <<<SQL
        INSERT INTO tasks (project_id, script, payload)
        SELECT DISTINCT a.project_id, 'recheck_domains', JSON_OBJECT('project_id', a.project_id)
        FROM app a
        WHERE a.verified_domain IS NOT NULL
          AND a.deleted_at IS NULL
          AND NOT EXISTS (
            SELECT 1 FROM tasks t
            WHERE t.project_id = a.project_id AND t.script = 'recheck_domains'
              AND t.started_at IS NULL AND t.completed_at IS NULL AND t.failed_at IS NULL
          )
        SQL;

    $queued = Platform_task::execute($runtime->db(), $sql);

    $log->info("Queued recheck_domains for {$queued} project(s)");
    $runtime->record_periodic_summary('schedule_domain_rechecks', ['projects_queued' => $queued]);

    return 0;
};
