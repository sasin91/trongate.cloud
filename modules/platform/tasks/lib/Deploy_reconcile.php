<?php

require_once __DIR__ . '/Platform_task.php';
require_once __DIR__ . '/../../worker/Worker_actions.php';

/**
 * Deploy Reconcile
 *
 * The backstop for GitHub push webhooks that never arrive. GitHub does not
 * retry a delivery that failed to connect, so a push can be missed silently
 * and the app stays on the old commit until someone deploys by hand.
 *
 * Each run compares every auto-deploying app's branch head on GitHub with
 * its deployments and queues a deploy (trigger 'reconcile') for a head that
 * nothing has built. It applies the gates the webhook path would have:
 *
 * - only apps that deploy on push: Deploy on push on (app.auto_deploy,
 *   the customer's switch) and a webhook deployment at some point, so
 *   manual-only apps never cost a GitHub call;
 * - before queueing, the app's webhook must still be on the repository
 *   (GitHub hooks API; the classic `repo` scope onboarding asks for covers it).
 *   A customer who deletes the webhook to stop push deploys gets none from
 *   here either, and a token that may not read hooks deploys nothing;
 * - the app's own repository and branch, and a full 40-char head SHA;
 * - a SHA with ANY deployment row is never queued again, whatever its status:
 *   a failed build is for a human to retry, not for this job to loop on;
 * - heads younger than MIN_HEAD_AGE_SECONDS are left to the webhook in flight;
 * - nothing is queued while the app has a deployment pending or running,
 *   so the one-queued-deploy rule never replaces someone's commit; the next
 *   run picks the head up.
 *
 * A head still undeployed STUCK_HEAD_AGE_SECONDS after its commit for a
 * reason nobody will fix on their own (STUCK_OUTCOMES, e.g. a deployment
 * that never finishes) is counted as stuck: app_deploy_reconcile_stuck_heads.
 *
 * Deploys go through Platform_actions::queue_deploy like the UI, webhooks and
 * MCP. GitHub calls use the token of the customer who added the app (as
 * deploy.php does), one per repository branch, spaced by GITHUB_DELAY_MS; a
 * rate-limited or rejected token skips the remaining apps using it until the
 * next run.
 *
 * The worker runs one task at a time, so a run must not hold up customer
 * deploys: it stops as soon as a project task is waiting, and when GitHub itself
 * fails (5xx or no connection, after the client's retries). The rotation in
 * apps() resumes with the unchecked apps next run.
 *
 * Framework-free; lives in tasks/lib/ so the Runtime never runs it as a task.
 */
class Deploy_reconcile {

    /** Leave a head this young to its own webhook */
    const MIN_HEAD_AGE_SECONDS = 300;

    /** Undeployed this long (and not on purpose) counts as stuck */
    const STUCK_HEAD_AGE_SECONDS = 900;

    /**
     * Outcomes where the head is undeployed and nothing will deploy it.
     * too-recent is a webhook in flight, head-failed and no-webhook are on
     * purpose; none of them is a lost push.
     */
    const STUCK_OUTCOMES = ['deploy-active', 'queue-failed', 'webhook-unverifiable'];

    /** Most apps checked per run, longest unchecked first (app.reconciled_at) */
    const MAX_APPS_PER_RUN = 200;

    /** Pause between GitHub API calls */
    const GITHUB_DELAY_MS = 250;

    const OUTCOMES = [
        'in-sync',        // the head has a deployment
        'head-failed',    // the head's deployment failed; a human retries it
        'recovered',      // a missed push: deploy queued
        'too-recent',     // its webhook may still be on the way
        'deploy-active',  // a deployment is pending or running
        'no-token',       // whoever added the app has no GitHub token
        'token-rejected', // GitHub refused the token (401/403)
        'rate-limited',   // GitHub rate limit; retried next run
        'github-unavailable', // GitHub 5xx or unreachable; the run stops
        'branch-missing', // repository or branch not found (404)
        'no-webhook',     // the webhook is gone from the repository: not a missed push
        'webhook-unverifiable', // the token may not read the repository's hooks
        'queue-failed',
        'error',
    ];

    private mysqli $db;

    private Runtime $runtime;

    private Task_log $log;

    private Worker_actions $actions;

    /** @var array<int, Github|string> Owner (customer) id => client, or why their apps are skipped */
    private array $clients = [];

    /** @var array<string, array{sha: string, committed_at: ?int}> "owner|repo|branch" => head */
    private array $heads = [];

    private int $github_calls = 0;

    /** Seconds since the commit of the head the last reconcile() compared, if known */
    private ?int $head_age = null;

    function __construct(Runtime $runtime, Task_log $log) {
        $this->runtime = $runtime;
        $this->db = $runtime->db();
        $this->log = $log;
        $this->actions = new Worker_actions($this->db);
    }

    /**
     * Check every auto-deploying app once.
     *
     * @return array{scanned: int, github_calls: int, stuck: int, stopped: ?string, outcomes: array<string, int>}
     */
    function run(): array {
        $apps = $this->apps();
        $outcomes = array_fill_keys(self::OUTCOMES, 0);
        $scanned = 0;
        $stuck = 0;
        $stopped = null;

        if (count($apps) > self::MAX_APPS_PER_RUN) {
            $this->log->warn(sprintf('More than %d auto-deploying apps; checking those checked longest ago', self::MAX_APPS_PER_RUN));
            $apps = array_slice($apps, 0, self::MAX_APPS_PER_RUN);
        }

        foreach ($apps as $app) {
            // Customer deploys go first; the rotation picks up from here next run
            if ($this->project_task_waiting()) {
                $stopped = 'project-task-waiting';
                $this->log->info('A customer task is waiting; stopping until the next run');
                break;
            }

            try {
                $outcome = $this->reconcile($app);
            } catch (\Throwable $e) {
                // One app's failure must not stop the others being checked
                $this->log->error("App #{$app['id']}: {$e->getMessage()}");
                $outcome = 'error';
            }

            $this->mark_checked((int) $app['id']);
            $outcomes[$outcome]++;
            $scanned++;

            if (in_array($outcome, self::STUCK_OUTCOMES, true) && $this->head_age !== null && $this->head_age > self::STUCK_HEAD_AGE_SECONDS) {
                $stuck++;
                $this->log->warn(sprintf('App #%d: branch head undeployed for %d min (%s)', $app['id'], intdiv($this->head_age, 60), $outcome));
            }

            // Every further call would wait out the same timeouts and retries
            if ($outcome === 'github-unavailable') {
                $stopped = 'github-unavailable';
                break;
            }
        }

        $summary = [
            'scanned' => $scanned,
            'github_calls' => $this->github_calls,
            'stuck' => $stuck,
            'stopped' => $stopped,
            'outcomes' => $outcomes,
        ];

        $counts = array_filter($outcomes);
        $this->log->info(sprintf(
            'Checked %d app(s) with %d GitHub call(s)%s',
            $summary['scanned'],
            $this->github_calls,
            $counts === [] ? '' : ': ' . implode(', ', array_map(fn($outcome, $count) => "{$outcome}={$count}", array_keys($counts), $counts))
        ));

        return $summary;
    }

    /**
     * Apps with Deploy on push on whose webhook has deployed before; a
     * brand-new app's first deploy is queued by onboarding, not here.
     *
     * Longest unchecked first, so past MAX_APPS_PER_RUN the cap rotates.
     * Ordering by recent deployments would cut exactly the apps that lost
     * a push, since a lost push leaves no deployment behind.
     */
    function apps(): array {
        $sql = <<<SQL
            SELECT a.id, a.project_id, COALESCE(a.source_customer_id, p.owner_id) AS owner_id, a.source_url, a.source_branch
            FROM app a
            JOIN project p ON p.id = a.project_id
            WHERE a.source_provider = 'github'
              AND a.deleted_at IS NULL
              AND p.deleted_at IS NULL
              AND a.source_url <> ''
              AND a.auto_deploy = 1
              AND a.webhook_secret IS NOT NULL
              AND a.webhook_secret <> ''
              AND EXISTS (
                  SELECT 1 FROM deployment d
                  WHERE d.app_id = a.id
                    AND d.trigger_type = 'webhook'
              )
            ORDER BY a.reconciled_at IS NOT NULL, a.reconciled_at, a.id
            LIMIT ?
            SQL;

        $limit = self::MAX_APPS_PER_RUN + 1;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Whether a project (customer) task is queued and waiting for the worker.
     */
    function project_task_waiting(): bool {
        $row = Platform_task::row(
            $this->db,
            'SELECT id FROM tasks
             WHERE project_id IS NOT NULL
               AND started_at IS NULL
               AND completed_at IS NULL
               AND failed_at IS NULL
             LIMIT 1'
        );

        return $row !== null;
    }

    /**
     * Remember that the app was checked, for the rotation in apps().
     */
    function mark_checked(int $app_id): void {
        $stmt = $this->db->prepare('UPDATE app SET reconciled_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $app_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return string One of OUTCOMES
     */
    function reconcile(array $app): string {
        $app_id = (int) $app['id'];
        $project_id = (int) $app['project_id'];
        $owner_id = (int) $app['owner_id'];
        $branch = (string) ($app['source_branch'] ?: 'main');
        $this->head_age = null;

        if (!preg_match('#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(\.git)?/?$#', (string) $app['source_url'], $match)) {
            throw new RuntimeException('No valid GitHub repository URL');
        }

        $repo = $match[1];
        $github = $this->client($owner_id);

        if (is_string($github)) {
            return $github;
        }

        $key = strtolower("{$owner_id}|{$repo}|{$branch}");

        try {
            $head = $this->heads[$key] ??= $this->branch_head($github, $repo, $branch);
        } catch (Client_Error $e) {
            return $this->github_failure($owner_id, $app_id, $e);
        }

        $sha = $head['sha'];

        $built = Platform_task::row(
            $this->db,
            'SELECT id, status FROM deployment WHERE app_id = ? AND commit_sha = ? ORDER BY id DESC LIMIT 1',
            'is',
            $app_id,
            $sha
        );

        if ($built !== null) {
            return $built['status'] === 'failed' ? 'head-failed' : 'in-sync';
        }

        // No committer date: no way to tell a fresh push from a lost one, so wait
        $age = $this->head_age = $head['committed_at'] === null ? null : time() - $head['committed_at'];

        if ($age === null || $age < self::MIN_HEAD_AGE_SECONDS) {
            return 'too-recent';
        }

        // Checked only once the head is known to be undeployed, so a deployment
        // that never finishes shows up as a stuck head instead of hiding it
        $active = Platform_task::row(
            $this->db,
            "SELECT id FROM deployment WHERE app_id = ? AND status IN ('pending', 'building', 'deploying') LIMIT 1",
            'i',
            $app_id
        );

        if ($active !== null) {
            return 'deploy-active';
        }

        // The customer may have removed the webhook to stop push deploys
        try {
            if (!$this->webhook_exists($github, $repo, $app_id)) {
                return 'no-webhook';
            }
        } catch (Client_Error $e) {
            $rate_limited = stripos($e->getMessage(), 'rate limit') !== false;

            if ($e->getCode() === 404 || ($e->getCode() === 403 && !$rate_limited)) {
                $this->log->warn("App #{$app_id}: the GitHub token that added it may not read {$repo}'s webhooks; not deploying " . substr($sha, 0, 7));
                return 'webhook-unverifiable';
            }

            return $this->github_failure($owner_id, $app_id, $e);
        }

        try {
            $deployment_id = $this->actions->queue_deploy($project_id, $app_id, null, 'reconcile', $sha);
        } catch (\Throwable $e) {
            $this->log->error("App #{$app_id}: queueing a deploy of " . substr($sha, 0, 7) . " failed: {$e->getMessage()}");
            return 'queue-failed';
        }

        $this->log->warn(sprintf(
            'App #%d: %s@%s (%s) was never deployed, %d min after it was committed; queued deployment #%d',
            $app_id,
            $repo,
            substr($sha, 0, 7),
            $branch,
            intdiv($age, 60),
            $deployment_id
        ));

        return 'recovered';
    }

    /**
     * The branch's head commit. The branches endpoint (unlike commits/{ref})
     * leaves out the changed files, so the response stays small.
     *
     * @return array{sha: string, committed_at: ?int}
     */
    function branch_head(Github $github, string $repo, string $branch): array {
        if ($this->github_calls > 0) {
            usleep(self::GITHUB_DELAY_MS * 1000);
        }

        $this->github_calls++;
        $response = $github->request('GET', "/repos/{$repo}/branches/" . rawurlencode($branch));
        $sha = strtolower((string) ($response['commit']['sha'] ?? ''));

        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new RuntimeException("GitHub returned no head commit for {$repo}@{$branch}");
        }

        $date = (string) ($response['commit']['commit']['committer']['date'] ?? '');
        $committed_at = $date !== '' ? strtotime($date) : false;

        return ['sha' => $sha, 'committed_at' => $committed_at === false ? null : $committed_at];
    }

    /**
     * Whether the repository still has an active webhook pointing at this
     * app's endpoint (webhooks/github/{id}). Only asked for a head that is
     * about to be deployed, so it costs a call per missed push, not per app.
     *
     * @throws Client_Error 403/404 when the token may not read hooks
     */
    function webhook_exists(Github $github, string $repo, int $app_id): bool {
        usleep(self::GITHUB_DELAY_MS * 1000);

        $this->github_calls++;
        $hooks = $github->request('GET', "/repos/{$repo}/hooks?per_page=100");
        $path = "/webhooks/github/{$app_id}";

        foreach ($hooks as $hook) {
            $url = rtrim((string) ($hook['config']['url'] ?? ''), '/');

            if (!empty($hook['active']) && str_ends_with((string) parse_url($url, PHP_URL_PATH), $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A GitHub client with the customer's token, or the outcome that skips
     * the apps using it.
     */
    function client(int $owner_id): Github|string {
        if (!isset($this->clients[$owner_id])) {
            $token = Platform_task::github_token($this->runtime, $owner_id);

            $this->clients[$owner_id] = $token !== '' ? new Github($token) : 'no-token';
        }

        return $this->clients[$owner_id];
    }

    /**
     * Map a GitHub error to an outcome. A refused or rate-limited token skips
     * the owner's remaining apps this run instead of spending more calls.
     */
    function github_failure(int $owner_id, int $app_id, Client_Error $e): string {
        $code = $e->getCode();

        $outcome = match (true) {
            $code === 429, $code === 403 && stripos($e->getMessage(), 'rate limit') !== false => 'rate-limited',
            $code === 401, $code === 403 => 'token-rejected',
            $code === 404 => 'branch-missing',
            $code === 0, $code >= 500 => 'github-unavailable',
            default => 'error',
        };

        if ($outcome === 'token-rejected' || $outcome === 'rate-limited') {
            $this->clients[$owner_id] = $outcome;
            $this->log->warn("Customer #{$owner_id}'s GitHub token: GitHub answered {$code}; skipping the apps using it until the next run");
        } else {
            $this->log->warn("App #{$app_id}: {$e->getMessage()}");
        }

        return $outcome;
    }
}
