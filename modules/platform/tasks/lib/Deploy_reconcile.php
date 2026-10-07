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
 * - the trongate.cloud GitHub App must still be installed on the repository:
 *   a customer who removes it (or the repository from its selection) gets no
 *   deploys from here either;
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
 * MCP. GitHub calls use an installation token of the GitHub App per
 * repository (as deploy.php does), one per repository branch, spaced by
 * GITHUB_DELAY_MS; a rate-limited or rejected token skips the remaining apps
 * on that repository until the next run.
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
     * too-recent is a webhook in flight, head-failed and not-installed are on
     * purpose; none of them is a lost push.
     */
    const STUCK_OUTCOMES = ['deploy-active', 'queue-failed'];

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
        'not-installed',  // the GitHub App is not installed on the repository: not a missed push
        'token-rejected', // GitHub refused the App or its token (401/403)
        'rate-limited',   // GitHub rate limit; retried next run
        'github-unavailable', // GitHub 5xx or unreachable; the run stops
        'branch-missing', // repository or branch not found (404)
        'queue-failed',
        'error',
    ];

    private PDO $db;

    private Task_log $log;

    private Worker_actions $actions;

    /** @var array<string, Github|string> Repository (lowercase owner/name) => client, or why its apps are skipped */
    private array $clients = [];

    /** @var array<string, array{sha: string, committed_at: ?int}> "repo|branch" => head */
    private array $heads = [];

    private int $github_calls = 0;

    /** Seconds since the commit of the head the last reconcile() compared, if known */
    private ?int $head_age = null;

    function __construct(Runtime $runtime, Task_log $log) {
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
            if (Platform_task::project_task_waiting($this->db)) {
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
            SELECT a.id, a.project_id, a.source_url, a.source_branch
            FROM app a
            JOIN project p ON p.id = a.project_id
            WHERE a.source_provider = 'github'
              AND a.deleted_at IS NULL
              AND p.deleted_at IS NULL
              AND a.source_url <> ''
              AND a.auto_deploy = 1
              AND EXISTS (
                  SELECT 1 FROM deployment d
                  WHERE d.app_id = a.id
                    AND d.trigger_type = 'webhook'
              )
            ORDER BY a.reconciled_at IS NOT NULL, a.reconciled_at, a.id
            LIMIT ?
            SQL;

        $limit = self::MAX_APPS_PER_RUN + 1;
        return Platform_task::rows($this->db, $sql, [$limit]);
    }

    /**
     * Remember that the app was checked, for the rotation in apps().
     */
    function mark_checked(int $app_id): void {
        Platform_task::execute($this->db, 'UPDATE app SET reconciled_at = NOW() WHERE id = ?', [$app_id]);
    }

    /**
     * @return string One of OUTCOMES
     */
    function reconcile(array $app): string {
        $app_id = (int) $app['id'];
        $project_id = (int) $app['project_id'];
        $branch = (string) ($app['source_branch'] ?: 'main');
        $this->head_age = null;

        if (!preg_match('#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(\.git)?/?$#', (string) $app['source_url'], $match)) {
            throw new RuntimeException('No valid GitHub repository URL');
        }

        $repo = $match[1];
        $github = $this->client($repo, $app_id);

        if (is_string($github)) {
            return $github;
        }

        $key = strtolower("{$repo}|{$branch}");

        try {
            $head = $this->heads[$key] ??= $this->branch_head($github, $repo, $branch);
        } catch (Client_Error $e) {
            return $this->github_failure($repo, $app_id, $e);
        }

        $sha = $head['sha'];

        $built = Platform_task::row(
            $this->db,
            'SELECT id, status FROM deployment WHERE app_id = ? AND commit_sha = ? ORDER BY id DESC LIMIT 1',
            [$app_id, $sha],
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
            [$app_id]
        );

        if ($active !== null) {
            return 'deploy-active';
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
     * A GitHub client with an installation token for the repository, or the
     * outcome that skips the apps on it this run.
     */
    function client(string $repo, int $app_id): Github|string {
        $key = strtolower($repo);

        if (!isset($this->clients[$key])) {
            // The installation lookup and the token
            $this->github_calls += 2;

            try {
                $this->clients[$key] = Platform_task::github_client($repo);
            } catch (Client_Error $e) {
                $this->clients[$key] = $e->getCode() === 404 ? 'not-installed' : $this->github_failure($repo, $app_id, $e);
            }
        }

        return $this->clients[$key];
    }

    /**
     * Map a GitHub error to an outcome. A refused or rate-limited token skips
     * the repository's remaining apps this run instead of spending more calls.
     */
    function github_failure(string $repo, int $app_id, Client_Error $e): string {
        $code = $e->getCode();

        $outcome = match (true) {
            $code === 429, $code === 403 && stripos($e->getMessage(), 'rate limit') !== false => 'rate-limited',
            $code === 401, $code === 403 => 'token-rejected',
            $code === 404 => 'branch-missing',
            $code === 0, $code >= 500 => 'github-unavailable',
            default => 'error',
        };

        if ($outcome === 'token-rejected' || $outcome === 'rate-limited') {
            $this->clients[strtolower($repo)] = $outcome;
            $this->log->warn("GitHub App token for {$repo}: GitHub answered {$code}; skipping the apps on it until the next run");
        } else {
            $this->log->warn("App #{$app_id}: {$e->getMessage()}");
        }

        return $outcome;
    }
}
