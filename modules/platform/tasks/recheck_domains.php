<?php

/**
 * Recheck Domains Task
 *
 * The DNS gate for custom domains (db/019, Manifests::custom_domains). Checks
 * whether verified domains point at the platform (Domain_dns), records it, and
 * promotes a pointing domain in two steps, each by re-applying the app, so
 * nobody has to push a commit:
 *
 * 1. Routing: the app's live HTTPRoute does not list the domain yet. The
 *    re-apply gives it its Gateway, certificate request and route.
 * 2. BASE_URL: once its Certificate is Ready (waiting up to
 *    $certificate_wait_seconds right after step 1), domain_secured_at is set
 *    and the app is re-applied again with the domain as BASE_URL
 *    (Manifests::secured_domains). A certificate that never comes (CAA record,
 *    rate limit) leaves the app's links on the {slug} host.
 *
 * Comparing with the live objects (route hostnames, the Deployment's base-url
 * annotation) rather than only "newly pointing" makes every run catch up on a
 * promotion that was skipped (a deploy in progress) or failed (a rollout
 * timeout).
 *
 * Payload: {project_id, app_id?}. With app_id: that app. Without:
 * every app of the project with a verified domain.
 *
 * Queued by Platform_actions::verify_app_domain (verifying a domain, or
 * pressing Verify again on a verified one).
 *
 * Periodic: schedule_domain_rechecks (Runtime::PERIODIC_TASKS, every
 * DOMAIN_RECHECK_INTERVAL seconds) queues one per project with a verified domain,
 * so a domain goes live soon after its DNS change propagates and a domain that
 * moved away is noticed.
 *
 * Lessons kept from Scaleweb's CRM (hostname-gate.ts, domain-promotion.ts):
 * - Promotion re-applies the image of the app's current (successful)
 *   deployment. It never builds or deploys: a DNS edit at a registrar must not
 *   be able to ship new code.
 * - Promotion only ever adds a domain. Demotion, after $demote_after_failures
 *   failed checks in a row, only clears domain_pointed_at and
 *   domain_secured_at; the cluster is left alone and the next deploy stops
 *   routing the domain. A few flaky lookups
 *   must never take a live domain off the internet.
 * - Apps are checked and promoted one by one, and a failure is logged and
 *   skipped, so one broken domain never holds up another app's.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    // Consecutive failed checks before a routed domain counts as gone (CRM: 3)
    $demote_after_failures = 3;
    // How long to wait for a freshly routed domain's certificate (HTTP-01 usually takes well under a minute)
    $certificate_wait_seconds = 180;

    $db = $runtime->db();
    $project_id = (int) ($payload['project_id'] ?? 0);
    $app_id = (int) ($payload['app_id'] ?? 0);

    if ($project_id <= 0) {
        throw new InvalidArgumentException('recheck_domains needs project_id');
    }

    $sql = <<<SQL
        SELECT id
        FROM app
        WHERE project_id = ?
          AND verified_domain IS NOT NULL
          -- A deleted app keeps verified_domain until destroy_app
          -- finishes; promoting it would re-apply the app being torn down
          AND deleted_at IS NULL
          AND (? = 0 OR id = ?)
        ORDER BY id
        SQL;

    $app_ids = array_column(Platform_task::rows($db, $sql, 'iii', $project_id, $app_id, $app_id), 'id');

    if (!$app_ids) {
        $log->info('No verified custom domains to check');
        return 0;
    }

    /**
     * Re-apply a app whose domain points here but is not routed yet.
     * Returns false only when the re-apply itself failed. (A closure, not a
     * function: the worker includes this file once per task.)
     */
    $promote = function (int $id, string $domain) use ($runtime, $log, $project_id): bool {
        $db = $runtime->db();
        $app = Platform_task::app($db, $project_id, $id);

        // A deploy or rollback that is queued or running routes the domain itself
        // (Platform_task::apply re-reads the gate), and re-applying the current
        // image now could undo the newer one it is about to apply
        $sql = <<<SQL
            SELECT COUNT(*) AS busy
            FROM tasks
            WHERE app_id = ?
              AND script IN ('deploy', 'rollback')
              AND completed_at IS NULL
              AND failed_at IS NULL
            SQL;

        if ((int) (Platform_task::row($db, $sql, 'i', $id)['busy'] ?? 0) > 0) {
            $log->info("{$domain} points here; the deploy in progress will route it");
            return true;
        }

        $current_id = (int) ($app['current_deployment_id'] ?? 0);
        $deployment = $current_id > 0 ? Platform_task::row($db, 'SELECT * FROM deployment WHERE id = ? AND app_id = ?', 'ii', $current_id, $id) : null;

        if ($deployment === null || empty($deployment['image'])) {
            $log->info("{$domain} points here; it goes live with the app's first deploy");
            return true;
        }

        $log->info("Promoting {$domain}: re-applying deployment #{$current_id} ({$deployment['image']})");

        $database = Platform_task::database_server($db, $app, $log);
        Platform_task::apply($runtime, $log, $app, $deployment, $database);

        // BASE_URL changed, so the pods roll; the old ones serve until the new ones are ready
        if (!Platform_task::wait_for_rollout($runtime, $log, $app)) {
            return false;
        }

        $log->info('Applied; the app\'s base URL is ' . Trongate_config::base_url($app));

        return true;
    };

    /**
     * Whether the custom domain's Certificate (cert-manager creates it from the
     * domain's Gateway) is Ready, waiting up to $wait seconds. Logs why not.
     */
    $certificate_ready = function (int $id, string $domain, int $wait) use ($runtime, $log, $project_id): bool {
        $namespace = Manifests::namespace_name($project_id);
        $name = Manifests::domain_tls_secret(Manifests::domain_gateway_name($id, $domain));
        $deadline = time() + $wait;

        while (true) {
            $certificate = $runtime->kube()->get('cert-manager.io/v1', 'Certificate', $namespace, $name);
            $ready = [];

            foreach ($certificate['status']['conditions'] ?? [] as $condition) {
                if (($condition['type'] ?? '') === 'Ready') {
                    $ready = $condition;
                }
            }

            if (($ready['status'] ?? '') === 'True') {
                return true;
            }

            if (time() >= $deadline) {
                $why = $certificate === null ? 'no Certificate yet' : (string) ($ready['message'] ?? 'not ready');
                $log->info("{$domain}: HTTPS certificate not issued yet ({$why}). It stays off BASE_URL until it is.");
                return false;
            }

            sleep(10);
        }
    };

    $failed = 0;

    foreach ($app_ids as $id) {
        try {
            $app = Platform_task::app($db, $project_id, (int) $id);
            $domain = (string) $app['verified_domain'];
            $was_pointed = !empty($app['domain_pointed_at']);
            $check = Domain_dns::check($domain);

            if (!$check['points']) {
                // Assignments run left to right, so the demotion test sees the
                // incremented count. WHERE verified_domain: a domain that was
                // changed meanwhile keeps its fresh state.
                $sql = <<<SQL
                    UPDATE app
                    SET domain_dns_failures = LEAST(domain_dns_failures + 1, 255),
                        domain_pointed_at = IF(domain_dns_failures >= ?, NULL, domain_pointed_at),
                        domain_secured_at = IF(domain_pointed_at IS NULL, NULL, domain_secured_at),
                        domain_checked_at = NOW()
                    WHERE id = ? AND verified_domain = ?
                    SQL;
                Platform_task::execute($db, $sql, 'iis', $demote_after_failures, (int) $id, $domain);

                $failures = (int) (Platform_task::row($db, 'SELECT domain_dns_failures FROM app WHERE id = ?', 'i', (int) $id)['domain_dns_failures'] ?? 0);

                if ($was_pointed && $failures >= $demote_after_failures) {
                    $log->warn("{$domain}: {$check['detail']} ({$failures} checks in a row). No longer routed from the next deploy on.");
                } else {
                    $log->info("{$domain}: {$check['detail']}");
                }

                continue;
            }

            $sql = <<<SQL
                UPDATE app
                SET domain_dns_failures = 0,
                    domain_pointed_at = COALESCE(domain_pointed_at, NOW()),
                    domain_checked_at = NOW()
                WHERE id = ? AND verified_domain = ?
                SQL;
            Platform_task::execute($db, $sql, 'is', (int) $id, $domain);
            $log->info("{$domain}: {$check['detail']}");

            $namespace = Manifests::namespace_name($project_id);
            $app_name = Manifests::app_name((int) $id);

            // Step 1: routing
            $route = $runtime->kube()->get('gateway.networking.k8s.io/v1', 'HTTPRoute', $namespace, $app_name);
            $promoted = false;

            if ($route === null || !in_array($domain, $route['spec']['hostnames'] ?? [], true)) {
                if (!$promote((int) $id, $domain)) {
                    $failed++;
                    continue;
                }

                // Left to a deploy (in progress, or the first one): nothing to wait for yet
                $route = $runtime->kube()->get('gateway.networking.k8s.io/v1', 'HTTPRoute', $namespace, $app_name);

                if ($route === null || !in_array($domain, $route['spec']['hostnames'] ?? [], true)) {
                    continue;
                }

                $promoted = true;
            }

            // Step 2: BASE_URL, once the certificate is issued
            $app = Platform_task::app($db, $project_id, (int) $id);

            if (empty($app['domain_secured_at'])) {
                if (!$certificate_ready((int) $id, $domain, $promoted ? $certificate_wait_seconds : 0)) {
                    continue;
                }

                $sql = <<<SQL
                    UPDATE app
                    SET domain_secured_at = NOW()
                    WHERE id = ? AND verified_domain = ? AND domain_pointed_at IS NOT NULL AND domain_secured_at IS NULL
                    SQL;
                Platform_task::execute($db, $sql, 'is', (int) $id, $domain);
                $log->info("{$domain}: HTTPS certificate issued");
                $app = Platform_task::app($db, $project_id, (int) $id);
            }

            // No Deployment yet: the first deploy applies the right BASE_URL
            $deployment = $runtime->kube()->get('apps/v1', 'Deployment', $namespace, $app_name);
            $live_base_url = (string) ($deployment['spec']['template']['metadata']['annotations'][Manifests::LABEL_PREFIX . '/base-url'] ?? '');

            if ($deployment !== null && $live_base_url !== Trongate_config::base_url($app) && !$promote((int) $id, $domain)) {
                $failed++;
            }
        } catch (Throwable $e) {
            $failed++;
            $log->error("App #{$id}: {$e->getMessage()}");
        }
    }

    return $failed > 0 ? 1 : 0;
};
