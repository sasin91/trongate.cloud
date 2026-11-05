<?php

/**
 * Commit rules every Deploy_source shares
 */
trait Follows_branch {

    /**
     * Triggers that stand for "the branch moved": they always build the
     * branch head, so a replayed (validly signed) old push cannot roll the app
     * back to an older commit. Reconcile deploys stand in for a missed push.
     */
    const FOLLOWS_BRANCH = ['webhook', 'reconcile'];

    /**
     * @param array<string, mixed> $deployment
     */
    private static function follows_branch(array $deployment, string $commit_sha): bool {
        return $commit_sha !== '' && in_array($deployment['trigger_type'] ?? '', self::FOLLOWS_BRANCH, true);
    }

    /**
     * The branch head when a pushed commit is no longer it
     */
    private static function branch_head_instead(string $pushed_sha, string $head_sha, string $branch, Task_log $log): string {
        if ($head_sha === '' || $head_sha === $pushed_sha) {
            return $pushed_sha;
        }

        $log->warn('Pushed commit ' . substr($pushed_sha, 0, 7) . " is no longer the head of {$branch}; building the head instead");

        return $head_sha;
    }

    private static function full_sha_or_fail(string $commit_sha): string {
        if (!preg_match('/^[0-9a-f]{40}$/', $commit_sha)) {
            throw new RuntimeException('Could not resolve the commit to deploy');
        }

        return $commit_sha;
    }
}
