<?php

require_once __DIR__ . '/Platform_task.php';
require_once __DIR__ . '/../../git/Git_repo.php';

/**
 * The deploy task's Source step: which commit to build, and its files in the
 * build directory. From GitHub (the GitHub App's installation token, or none
 * for a public repository) or from trongate.cloud git (Git_repo, on the
 * platform-git volume).
 */
final class Deploy_source {

    /**
     * Triggers that stand for "the branch moved": they always build the
     * branch head, so a replayed (validly signed) old push cannot roll the app
     * back to an older commit. Reconcile deploys stand in for a missed push.
     */
    const FOLLOWS_BRANCH = ['webhook', 'reconcile', 'push'];

    static function github_client(string $repo): Github {
        try {
            return Platform_task::source_client($repo);
        } catch (Client_Error $e) {
            if ($e->getCode() === 404) {
                throw new RuntimeException("Cannot read {$repo}: install the trongate.cloud GitHub app on it, or make it public");
            }

            throw $e;
        }
    }

    /**
     * Full SHA of the GitHub commit to build
     */
    static function github_commit(Github $github, string $repo, string $branch, array $deployment, string $commit_sha, Task_log $log): string {
        $commit_sha = strtolower($commit_sha);

        if (in_array($deployment['trigger_type'] ?? '', self::FOLLOWS_BRANCH, true) && $commit_sha !== '') {
            $head = $github->request('GET', "/repos/{$repo}/commits/" . rawurlencode($branch));
            $commit_sha = self::branch_head_instead($commit_sha, strtolower((string) ($head['sha'] ?? '')), $branch, $log);
        }

        // No commit: the branch head. A short SHA (queue_deploy accepts 7-40 chars): its full SHA.
        if (!preg_match('/^[0-9a-f]{40}$/', $commit_sha)) {
            $ref = $commit_sha !== '' ? $commit_sha : $branch;
            $commit = $github->request('GET', "/repos/{$repo}/commits/" . rawurlencode($ref));
            $commit_sha = strtolower((string) ($commit['sha'] ?? ''));
        }

        return self::full_sha_or_fail($commit_sha);
    }

    /**
     * Full SHA of the trongate.cloud git commit to build
     */
    static function internal_commit(Git_repo $repo, string $branch, array $deployment, string $commit_sha, Task_log $log): string {
        $commit_sha = strtolower($commit_sha);

        if (in_array($deployment['trigger_type'] ?? '', self::FOLLOWS_BRANCH, true) && $commit_sha !== '') {
            $commit_sha = self::branch_head_instead($commit_sha, (string) $repo->resolve($branch), $branch, $log);
        }

        $ref = $commit_sha !== '' ? $commit_sha : $branch;
        $resolved = $repo->resolve($ref);

        if ($resolved === null && $commit_sha === '') {
            throw new RuntimeException("Branch {$branch} has no commits yet: push to it first");
        }

        return self::full_sha_or_fail((string) $resolved);
    }

    /**
     * The commit's files in $src, from GitHub's tarball. Downloaded in PHP so
     * the token never appears in a command line or log.
     */
    static function extract_github(Runtime $runtime, Task_log $log, Github $github, string $source_url, string $commit_sha, string $work, string $src): void {
        $q = fn(string $value) => Trongate_config::sh_quote($value);
        $tarball = "{$work}/source.tar.gz";
        $handle = fopen($tarball, 'wb');
        $curl = curl_init($github->download_url($source_url, $commit_sha));
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_HTTPHEADER => $github->headers(),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_MAXFILESIZE_LARGE => 500 * 1024 * 1024,
        ]);
        $downloaded = curl_exec($curl);
        $download_error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($downloaded !== true) {
            throw new RuntimeException("Downloading the repository failed: {$download_error}");
        }

        $log->info('Downloaded source (' . round(filesize($tarball) / 1024) . ' KiB)');

        if ($runtime->shell('tar -xzf ' . $q($tarball) . ' --strip-components=1 -C ' . $q($src) . ' && rm -f ' . $q($tarball), $log) !== 0) {
            throw new RuntimeException('Extracting the repository failed');
        }
    }

    /**
     * The commit's files in $src, from the bare repository (git archive)
     */
    static function extract_internal(Runtime $runtime, Task_log $log, Git_repo $repo, string $commit_sha, string $work, string $src): void {
        $q = fn(string $value) => Trongate_config::sh_quote($value);
        $tar = "{$work}/source.tar";
        $repo->archive($commit_sha, $tar);

        $log->info('Read source from trongate.cloud git (' . round(filesize($tar) / 1024) . ' KiB)');

        if ($runtime->shell('tar -xf ' . $q($tar) . ' -C ' . $q($src) . ' && rm -f ' . $q($tar), $log) !== 0) {
            throw new RuntimeException('Extracting the repository failed');
        }
    }

    /**
     * The branch head when a pushed commit is no longer it
     */
    static function branch_head_instead(string $pushed_sha, string $head_sha, string $branch, Task_log $log): string {
        if ($head_sha === '' || $head_sha === $pushed_sha) {
            return $pushed_sha;
        }

        $log->warn('Pushed commit ' . substr($pushed_sha, 0, 7) . " is no longer the head of {$branch}; building the head instead");

        return $head_sha;
    }

    static function full_sha_or_fail(string $commit_sha): string {
        if (!preg_match('/^[0-9a-f]{40}$/', $commit_sha)) {
            throw new RuntimeException('Could not resolve the commit to deploy');
        }

        return $commit_sha;
    }
}
