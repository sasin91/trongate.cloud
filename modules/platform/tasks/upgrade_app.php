<?php

/**
 * Upgrade App Task
 *
 * Opens a pull request on the app's repository that moves it to the newest
 * Trongate v2 release (Trongate_upgrade): finds the release the app's branch
 * is on, merges the framework's files and config settings, commits the result
 * on a new branch through GitHub's API (no checkout) and opens a pull request
 * against the app's branch. Nothing is deployed: merging the pull request
 * deploys it as any push does.
 *
 * Payload: {upgrade_id, app_id, project_id}
 *
 * The app_upgrade row carries the outcome for the app's Settings page and the
 * MCP tools. The installation token never reaches the log.
 */

require_once __DIR__ . '/lib/Platform_task.php';
require_once __DIR__ . '/../upgrade/Trongate_upgrade.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $db = $runtime->db();
    $project_id = (int) $payload['project_id'];
    $app_id = (int) $payload['app_id'];
    $upgrade_id = (int) $payload['upgrade_id'];

    $update = function (array $fields) use ($db, $upgrade_id, $app_id): void {
        $set = implode(', ', array_map(fn(string $column) => "{$column} = ?", array_keys($fields)));
        Platform_task::execute($db, "UPDATE app_upgrade SET {$set} WHERE id = ? AND app_id = ?", [...array_values($fields), $upgrade_id, $app_id]);
    };
    $finish = fn(array $fields) => $update($fields + ['completed_at' => date('Y-m-d H:i:s')]);

    try {
        $app = Platform_task::app($db, $project_id, $app_id);
        $update(['status' => 'running']);

        if (($app['type'] ?? '') !== 'trongate_v2') {
            throw new RuntimeException('Upgrades are for Trongate v2 apps.');
        }

        $repo = Platform_task::github_repo($app);
        $branch = (string) ($app['source_branch'] ?: 'main');

        try {
            $github = new Github(Github_app::from_env()->repository_token($repo, ['contents' => 'write', 'pull_requests' => 'write']));
        } catch (Client_Error $e) {
            throw new RuntimeException(match ($e->getCode()) {
                404 => "The trongate.cloud GitHub app is not installed on {$repo}.",
                422 => "The trongate.cloud GitHub app may not open pull requests on {$repo} yet. Accept its new permissions (contents and pull requests: write) in your GitHub settings, then try again.",
                default => $e->getMessage(),
            }, 0, $e);
        }

        $head = $github->branch_sha($repo, $branch) ?? throw new RuntimeException("{$repo} has no branch {$branch}.");
        $app_tree = $github->tree($repo, $head);
        $log->info("Reading {$repo}@" . substr($head, 0, 7) . " ({$branch})");

        // Release tags, a few pages at most
        $tags = [];

        for ($page = 1; $page <= 3; $page++) {
            $batch = $github->request('GET', '/repos/' . Trongate_upgrade::SOURCE . "/tags?per_page=100&page={$page}");
            array_push($tags, ...array_column($batch, 'name'));

            if (count($batch) < 100) {
                break;
            }
        }

        $releases = array_slice(Trongate_upgrade::releases($tags), 0, Trongate_upgrade::MAX_RELEASES, true);

        if ($releases === []) {
            throw new RuntimeException('Found no Trongate v2 releases on GitHub.');
        }

        $trees = array_map(fn(string $tag) => $github->tree(Trongate_upgrade::SOURCE, $tag), $releases);
        $from = Trongate_upgrade::closest($app_tree, $trees);
        $to = (string) array_key_first($releases);

        if ($from === null) {
            throw new RuntimeException("Could not tell which Trongate release {$branch} is on: its engine/ folder matches none of the last " . count($releases) . ' releases.');
        }

        $log->info("On Trongate {$from}; newest is {$to}");

        if ($from === $to) {
            $finish(['status' => 'current', 'from_version' => $from, 'to_version' => $to]);
            $log->info('Already on the newest release');

            return 0;
        }

        $blobs = [];
        $read = function (string $side, string $path) use ($github, $repo, $app_tree, $trees, $from, $to, &$blobs): string {
            [$source, $sha] = match ($side) {
                'app' => [$repo, $app_tree[$path]],
                'base' => [Trongate_upgrade::SOURCE, $trees[$from][$path]],
                default => [Trongate_upgrade::SOURCE, $trees[$to][$path]],
            };

            return $blobs[$sha] ??= $github->blob($source, $sha);
        };

        $plan = (new Trongate_upgrade($trees[$from], $trees[$to], $app_tree, $read))->plan();
        $counts = $plan->counts();
        $summary = json_encode($counts, JSON_THROW_ON_ERROR);

        foreach ($counts as $kind => $count) {
            if ($count > 0) {
                $log->info("{$kind}: {$count}");
            }
        }

        if ($plan->changes === []) {
            $finish(['status' => 'current', 'from_version' => $from, 'to_version' => $to, 'summary' => $summary]);
            $log->info('Nothing to change');

            return 0;
        }

        $upgrade_branch = "trongate-upgrade/{$to}";
        $open = $github->open_pull_request($repo, $upgrade_branch);

        if ($open !== null) {
            $finish(['status' => 'opened', 'from_version' => $from, 'to_version' => $to, 'pr_url' => (string) $open['html_url'], 'summary' => $summary]);
            $log->info("A pull request for {$to} is already open: {$open['html_url']}");

            return 0;
        }

        // A branch left from an earlier, closed pull request stays; this run gets its own
        if ($github->branch_sha($repo, $upgrade_branch) !== null) {
            $upgrade_branch .= '-' . date('YmdHis');
        }

        $commit = $github->commit_files($repo, $head, $plan->changes, "Upgrade Trongate to {$to}\n\nFrom {$from}, by trongate.cloud.");
        $github->create_branch($repo, $upgrade_branch, $commit);

        $conflicts = count($plan->files['conflicts']);
        $title = "Upgrade Trongate to {$to}" . ($conflicts > 0 ? " ({$conflicts} " . ($conflicts === 1 ? 'conflict' : 'conflicts') . ' to resolve)' : '');
        // GitHub's limit is 65536 characters
        $body = mb_strcut($plan->description($from, $to, Trongate_upgrade::SOURCE), 0, 60000);
        $pull = $github->create_pull_request($repo, $upgrade_branch, $branch, $title, $body);

        $finish(['status' => 'opened', 'from_version' => $from, 'to_version' => $to, 'pr_url' => (string) $pull['html_url'], 'summary' => $summary]);
        $log->info("Opened {$pull['html_url']}");

        return 0;
    } catch (Throwable $e) {
        $finish(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

        throw $e;
    }
};
