<?php

/**
 * Recheck Domains Task
 *
 * The DNS gate for custom domains (db/016, Manifests::custom_domains). Checks
 * whether verified domains point at the platform (Domain_dns), records it, and
 * promotes a pointing domain the app's live HTTPRoute does not list yet: the
 * app is re-applied, so the domain gets its Gateway, certificate, route and
 * BASE_URL without anyone pushing a commit. Comparing with the live route
 * rather than only "newly pointing" makes every run catch up on a promotion
 * that was skipped (a deploy in progress) or failed (a rollout timeout).
 *
 * Payload: {team_id, project_id?}. With project_id: that project. Without:
 * every project of the team with a verified domain.
 *
 * Queued by Platform_actions::verify_project_domain (verifying a domain, or
 * pressing Verify again on a verified one).
 *
 * PERIODIC USE: it is also meant to run on a schedule, so a domain goes live
 * soon after its DNS change propagates and a domain that moved away is noticed.
 * This file does not schedule itself. The scheduler should, every 10 minutes or
 * so, queue one task per team with a verified domain, skipping teams that still
 * have one waiting:
 *
 *   INSERT INTO tasks (team_id, script, payload)
 *   SELECT DISTINCT p.team_id, 'recheck_domains', JSON_OBJECT('team_id', p.team_id)
 *   FROM project p
 *   WHERE p.verified_domain IS NOT NULL
 *     AND NOT EXISTS (
 *       SELECT 1 FROM tasks t
 *       WHERE t.team_id = p.team_id AND t.script = 'recheck_domains'
 *         AND t.started_at IS NULL AND t.completed_at IS NULL AND t.failed_at IS NULL
 *     );
 *
 * Lessons kept from Scaleweb's CRM (hostname-gate.ts, domain-promotion.ts):
 * - Promotion re-applies the image of the project's current (successful)
 *   deployment. It never builds or deploys: a DNS edit at a registrar must not
 *   be able to ship new code.
 * - Promotion only ever adds a domain. Demotion, after $demote_after_failures
 *   failed checks in a row, only clears domain_pointed_at; the cluster is left
 *   alone and the next deploy stops routing the domain. A few flaky lookups
 *   must never take a live domain off the internet.
 * - Projects are checked and promoted one by one, and a failure is logged and
 *   skipped, so one broken domain never holds up another project's.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    // Consecutive failed checks before a routed domain counts as gone (CRM: 3)
    $demote_after_failures = 3;

    $db = $runtime->db();
    $team_id = (int) ($payload['team_id'] ?? 0);
    $project_id = (int) ($payload['project_id'] ?? 0);

    if ($team_id <= 0) {
        throw new InvalidArgumentException('recheck_domains needs team_id');
    }

    $sql = <<<SQL
        SELECT id
        FROM project
        WHERE team_id = ?
          AND verified_domain IS NOT NULL
          AND (? = 0 OR id = ?)
        ORDER BY id
        SQL;

    $project_ids = array_column(Platform_task::rows($db, $sql, 'iii', $team_id, $project_id, $project_id), 'id');

    if (!$project_ids) {
        $log->info('No verified custom domains to check');
        return 0;
    }

    /**
     * Re-apply a project whose domain points here but is not routed yet.
     * Returns false only when the re-apply itself failed. (A closure, not a
     * function: the worker includes this file once per task.)
     */
    $promote = function (int $id, string $domain) use ($runtime, $log, $team_id): bool {
        $db = $runtime->db();
        $project = Platform_task::project($db, $team_id, $id);

        // A deploy or rollback that is queued or running routes the domain itself
        // (Platform_task::apply re-reads the gate), and re-applying the current
        // image now could undo the newer one it is about to apply
        $sql = <<<SQL
            SELECT COUNT(*) AS busy
            FROM tasks
            WHERE project_id = ?
              AND script IN ('deploy', 'rollback')
              AND completed_at IS NULL
              AND failed_at IS NULL
            SQL;

        if ((int) (Platform_task::row($db, $sql, 'i', $id)['busy'] ?? 0) > 0) {
            $log->info("{$domain} points here; the deploy in progress will route it");
            return true;
        }

        $current_id = (int) ($project['current_deployment_id'] ?? 0);
        $deployment = $current_id > 0 ? Platform_task::row($db, 'SELECT * FROM deployment WHERE id = ? AND project_id = ?', 'ii', $current_id, $id) : null;

        if ($deployment === null || empty($deployment['image'])) {
            $log->info("{$domain} points here; it goes live with the project's first deploy");
            return true;
        }

        $log->info("Promoting {$domain}: re-applying deployment #{$current_id} ({$deployment['image']})");

        $database = Platform_task::database_server($db, $project, $log);
        Platform_task::apply($runtime, $log, $project, $deployment, $database);

        // BASE_URL changed, so the pods roll; the old ones serve until the new ones are ready
        if (!Platform_task::wait_for_rollout($runtime, $log, $project)) {
            return false;
        }

        $log->info('Live at ' . Trongate_config::base_url($project) . ' once its certificate is issued (usually within minutes)');

        return true;
    };

    $failed = 0;

    foreach ($project_ids as $id) {
        try {
            $project = Platform_task::project($db, $team_id, (int) $id);
            $domain = (string) $project['verified_domain'];
            $was_pointed = !empty($project['domain_pointed_at']);
            $check = Domain_dns::check($domain);

            if (!$check['points']) {
                // Assignments run left to right, so the demotion test sees the
                // incremented count. WHERE verified_domain: a domain that was
                // changed meanwhile keeps its fresh state.
                $sql = <<<SQL
                    UPDATE project
                    SET domain_dns_failures = LEAST(domain_dns_failures + 1, 255),
                        domain_pointed_at = IF(domain_dns_failures >= ?, NULL, domain_pointed_at),
                        domain_checked_at = NOW()
                    WHERE id = ? AND verified_domain = ?
                    SQL;
                Platform_task::execute($db, $sql, 'iis', $demote_after_failures, (int) $id, $domain);

                $failures = (int) (Platform_task::row($db, 'SELECT domain_dns_failures FROM project WHERE id = ?', 'i', (int) $id)['domain_dns_failures'] ?? 0);

                if ($was_pointed && $failures >= $demote_after_failures) {
                    $log->warn("{$domain}: {$check['detail']} ({$failures} checks in a row). No longer routed from the next deploy on.");
                } else {
                    $log->info("{$domain}: {$check['detail']}");
                }

                continue;
            }

            $sql = <<<SQL
                UPDATE project
                SET domain_dns_failures = 0,
                    domain_pointed_at = COALESCE(domain_pointed_at, NOW()),
                    domain_checked_at = NOW()
                WHERE id = ? AND verified_domain = ?
                SQL;
            Platform_task::execute($db, $sql, 'is', (int) $id, $domain);
            $log->info("{$domain}: {$check['detail']}");

            $route = $runtime->kube()->get('gateway.networking.k8s.io/v1', 'HTTPRoute', Manifests::namespace_name($team_id), Manifests::app_name((int) $id));

            if ($route !== null && in_array($domain, $route['spec']['hostnames'] ?? [], true)) {
                continue;
            }

            if (!$promote((int) $id, $domain)) {
                $failed++;
            }
        } catch (Throwable $e) {
            $failed++;
            $log->error("Project #{$id}: {$e->getMessage()}");
        }
    }

    return $failed > 0 ? 1 : 0;
};
