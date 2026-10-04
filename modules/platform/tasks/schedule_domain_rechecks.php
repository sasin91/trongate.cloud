<?php

/**
 * Schedule Domain Rechecks Task
 *
 * Periodic (Runtime::PERIODIC_TASKS, every DOMAIN_RECHECK_INTERVAL seconds):
 * queues recheck_domains for each team with a verified custom domain on a live
 * project, skipping a team that still has one waiting. recheck_domains is
 * team-scoped (it re-applies apps in the team's namespace), so the platform-wide
 * schedule only fans out; the checks themselves run as the team's tasks.
 *
 * Payload: {} (platform-wide, no team).
 *
 * In scope (see Runtime): $payload, $task, $runtime.
 * Returns a Closure(Task_log): int. The run's summary is saved for /metrics.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($runtime): int {
    $sql = <<<SQL
        INSERT INTO tasks (team_id, script, payload)
        SELECT DISTINCT p.team_id, 'recheck_domains', JSON_OBJECT('team_id', p.team_id)
        FROM project p
        WHERE p.verified_domain IS NOT NULL
          AND p.deleted_at IS NULL
          AND NOT EXISTS (
            SELECT 1 FROM tasks t
            WHERE t.team_id = p.team_id AND t.script = 'recheck_domains'
              AND t.started_at IS NULL AND t.completed_at IS NULL AND t.failed_at IS NULL
          )
        SQL;

    $queued = Platform_task::execute($runtime->db(), $sql);

    $log->info("Queued recheck_domains for {$queued} team(s)");
    $runtime->record_periodic_summary('schedule_domain_rechecks', ['teams_queued' => $queued]);

    return 0;
};
