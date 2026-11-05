<?php

require_once __DIR__ . '/Deploy_source.php';
require_once __DIR__ . '/Follows_branch.php';
require_once __DIR__ . '/../Platform_task.php';
require_once __DIR__ . '/../../../git/Git_repo.php';

/**
 * A GitHub repository, read with the GitHub App's installation token, or
 * without one for a public repository (a pasted link)
 */
final class Github_source implements Deploy_source {
    use Follows_branch;

    private function __construct(private readonly Github $github, private readonly string $repo) {
    }

    /**
     * @param array<string, mixed> $app
     */
    static function for_app(array $app): self {
        $repo = Platform_task::github_repo($app);

        try {
            return new self(Platform_task::source_client($repo), $repo);
        } catch (Client_Error $e) {
            if ($e->getCode() === 404) {
                throw new RuntimeException("Cannot read {$repo}: install the trongate.cloud GitHub app on it, or make it public");
            }

            throw $e;
        }
    }

    function name(): string {
        return $this->repo;
    }

    function commit(string $branch, array $deployment, string $commit_sha, Task_log $log): string {
        $commit_sha = strtolower($commit_sha);

        if (self::follows_branch($deployment, $commit_sha)) {
            $head = $this->github->request('GET', "/repos/{$this->repo}/commits/" . rawurlencode($branch));
            $commit_sha = self::branch_head_instead($commit_sha, strtolower((string) ($head['sha'] ?? '')), $branch, $log);
        }

        // No commit: the branch head. A short SHA (queue_deploy accepts 7-40 chars): its full SHA.
        if (!preg_match('/^[0-9a-f]{40}$/', $commit_sha)) {
            $ref = $commit_sha !== '' ? $commit_sha : $branch;
            $commit = $this->github->request('GET', "/repos/{$this->repo}/commits/" . rawurlencode($ref));
            $commit_sha = strtolower((string) ($commit['sha'] ?? ''));
        }

        return self::full_sha_or_fail($commit_sha);
    }

    /**
     * From GitHub's tarball. Downloaded in PHP so the token never appears in a
     * command line or log.
     */
    function extract(Runtime $runtime, Task_log $log, string $commit_sha, string $work, string $src): void {
        $q = fn(string $value) => Trongate_config::sh_quote($value);
        $tarball = "{$work}/source.tar.gz";
        $handle = fopen($tarball, 'wb');
        $curl = curl_init($this->github->download_url($this->github->url($this->repo), $commit_sha));
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_HTTPHEADER => $this->github->headers(),
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
     * The repository's size on GitHub in KiB
     */
    function size_kib(): int {
        return $this->github->repository_size_kib($this->repo);
    }

    /**
     * Every branch, with its history, into a trongate.cloud git repository
     * (import_git)
     */
    function fetch_into(Git_repo $repo): void {
        $repo->fetch_branches($this->github->url($this->repo) . '.git', $this->github->git_http_header());
    }
}
