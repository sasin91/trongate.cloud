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
 * Each run compares every auto-deploying project's branch head on GitHub with
 * its deployments and queues a deploy (trigger 'reconcile') for a head that
 * nothing has built. It applies the gates the webhook path would have:
 *
 * - only projects that deploy on push: a webhook secret and at least one
 *   webhook (or reconcile) deployment, so manual-only projects stay manual;
 * - the project's own repository and branch, and a full 40-char head SHA;
 * - a SHA with ANY deployment row is never queued again, whatever its status:
 *   a failed build is for a human to retry, not for this job to loop on;
 * - heads younger than MIN_HEAD_AGE_SECONDS are left to the webhook in flight;
 * - nothing is queued while the project has a deployment pending or running,
 *   so the one-queued-deploy rule never replaces someone's commit; the next
 *   run picks the head up.
 *
 * Deploys go through Platform_actions::queue_deploy like the UI, webhooks and
 * MCP. GitHub calls use the team's stored token (as deploy.php does), one per
 * repository branch, spaced by GITHUB_DELAY_MS; a rate-limited or rejected
 * token skips the team's remaining projects until the next run.
 *
 * Framework-free; lives in tasks/lib/ so the Runtime never runs it as a task.
 */
class Deploy_reconcile {

    /** Leave a head this young to its own webhook */
    const MIN_HEAD_AGE_SECONDS = 300;

    /** Most projects checked per run, most recently deployed first */
    const MAX_PROJECTS_PER_RUN = 200;

    /** Pause between GitHub API calls */
    const GITHUB_DELAY_MS = 250;

    const OUTCOMES = [
        'in-sync',        // the head has a deployment
        'head-failed',    // the head's deployment failed; a human retries it
        'recovered',      // a missed push: deploy queued
        'too-recent',     // its webhook may still be on the way
        'deploy-active',  // a deployment is pending or running
        'no-token',       // the team has no GitHub token
        'token-rejected', // GitHub refused the token (401/403)
        'rate-limited',   // GitHub rate limit; retried next run
        'branch-missing', // repository or branch not found (404)
        'queue-failed',
        'error',
    ];

    private mysqli $db;

    private Runtime $runtime;

    private Task_log $log;

    private Worker_actions $actions;

    /** @var array<int, Github|string> Team id => client, or why the team is skipped */
    private array $clients = [];

    /** @var array<string, array{sha: string, committed_at: ?int}> "team|repo|branch" => head */
    private array $heads = [];

    private int $github_calls = 0;

    function __construct(Runtime $runtime, Task_log $log) {
        $this->runtime = $runtime;
        $this->db = $runtime->db();
        $this->log = $log;
        $this->actions = new Worker_actions($this->db);
    }

    /**
     * Check every auto-deploying project once.
     *
     * @return array{scanned: int, github_calls: int, outcomes: array<string, int>}
     */
    function run(): array {
        $projects = $this->projects();
        $outcomes = array_fill_keys(self::OUTCOMES, 0);

        if (count($projects) > self::MAX_PROJECTS_PER_RUN) {
            $this->log->warn(sprintf('More than %d auto-deploying projects; checking the most recently deployed', self::MAX_PROJECTS_PER_RUN));
            $projects = array_slice($projects, 0, self::MAX_PROJECTS_PER_RUN);
        }

        foreach ($projects as $project) {
            try {
                $outcome = $this->reconcile($project);
            } catch (\Throwable $e) {
                // One project's failure must not stop the others being checked
                $this->log->error("Project #{$project['id']}: {$e->getMessage()}");
                $outcome = 'error';
            }

            $outcomes[$outcome]++;
        }

        $summary = [
            'scanned' => count($projects),
            'github_calls' => $this->github_calls,
            'outcomes' => $outcomes,
        ];

        $counts = array_filter($outcomes);
        $this->log->info(sprintf(
            'Checked %d project(s) with %d GitHub call(s)%s',
            $summary['scanned'],
            $this->github_calls,
            $counts === [] ? '' : ': ' . implode(', ', array_map(fn($outcome, $count) => "{$outcome}={$count}", array_keys($counts), $counts))
        ));

        return $summary;
    }

    /**
     * Projects that deploy on push and have been deployed before; a brand-new
     * project's first deploy is queued by onboarding, not here.
     */
    function projects(): array {
        $sql = <<<SQL
            SELECT p.id, p.team_id, p.source_url, p.source_branch
            FROM project p
            WHERE p.source_provider = 'github'
              AND p.source_url <> ''
              AND p.webhook_secret IS NOT NULL
              AND p.webhook_secret <> ''
              AND EXISTS (
                  SELECT 1 FROM deployment d
                  WHERE d.project_id = p.id
                    AND d.trigger_type IN ('webhook', 'reconcile')
              )
            ORDER BY (SELECT MAX(d.id) FROM deployment d WHERE d.project_id = p.id) DESC
            LIMIT ?
            SQL;

        $limit = self::MAX_PROJECTS_PER_RUN + 1;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * @return string One of OUTCOMES
     */
    function reconcile(array $project): string {
        $project_id = (int) $project['id'];
        $team_id = (int) $project['team_id'];
        $branch = (string) ($project['source_branch'] ?: 'main');

        // No GitHub call while a deploy is queued or running: it builds the head
        $active = Platform_task::row(
            $this->db,
            "SELECT id FROM deployment WHERE project_id = ? AND status IN ('pending', 'building', 'deploying') LIMIT 1",
            'i',
            $project_id
        );

        if ($active !== null) {
            return 'deploy-active';
        }

        if (!preg_match('#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(\.git)?/?$#', (string) $project['source_url'], $match)) {
            throw new RuntimeException('No valid GitHub repository URL');
        }

        $repo = $match[1];
        $github = $this->client($team_id);

        if (is_string($github)) {
            return $github;
        }

        $key = strtolower("{$team_id}|{$repo}|{$branch}");

        try {
            $head = $this->heads[$key] ??= $this->branch_head($github, $repo, $branch);
        } catch (Client_Error $e) {
            return $this->github_failure($team_id, $project_id, $e);
        }

        $sha = $head['sha'];

        $built = Platform_task::row(
            $this->db,
            'SELECT id, status FROM deployment WHERE project_id = ? AND commit_sha = ? ORDER BY id DESC LIMIT 1',
            'is',
            $project_id,
            $sha
        );

        if ($built !== null) {
            return $built['status'] === 'failed' ? 'head-failed' : 'in-sync';
        }

        // No committer date: no way to tell a fresh push from a lost one, so wait
        $age = $head['committed_at'] === null ? null : time() - $head['committed_at'];

        if ($age === null || $age < self::MIN_HEAD_AGE_SECONDS) {
            return 'too-recent';
        }

        try {
            $deployment_id = $this->actions->queue_deploy($team_id, $project_id, null, null, 'reconcile', $sha);
        } catch (\Throwable $e) {
            $this->log->error("Project #{$project_id}: queueing a deploy of " . substr($sha, 0, 7) . " failed: {$e->getMessage()}");
            return 'queue-failed';
        }

        $this->log->warn(sprintf(
            'Project #%d: %s@%s (%s) was never deployed, %d min after it was committed; queued deployment #%d',
            $project_id,
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
     * The team's GitHub client, or the outcome that skips its projects.
     */
    function client(int $team_id): Github|string {
        if (!isset($this->clients[$team_id])) {
            $secret = $this->runtime->resolve_reference("secrets:team,{$team_id},github");
            $token = is_array($secret) ? (string) ($secret['token'] ?? '') : '';

            $this->clients[$team_id] = $token !== '' ? new Github($token) : 'no-token';
        }

        return $this->clients[$team_id];
    }

    /**
     * Map a GitHub error to an outcome. A refused or rate-limited token skips
     * the team's remaining projects this run instead of spending more calls.
     */
    function github_failure(int $team_id, int $project_id, Client_Error $e): string {
        $code = $e->getCode();

        $outcome = match (true) {
            $code === 429, $code === 403 && stripos($e->getMessage(), 'rate limit') !== false => 'rate-limited',
            $code === 401, $code === 403 => 'token-rejected',
            $code === 404 => 'branch-missing',
            default => 'error',
        };

        if ($outcome === 'token-rejected' || $outcome === 'rate-limited') {
            $this->clients[$team_id] = $outcome;
            $this->log->warn("Team #{$team_id}: GitHub answered {$code}; skipping its projects until the next run");
        } else {
            $this->log->warn("Project #{$project_id}: {$e->getMessage()}");
        }

        return $outcome;
    }
}
