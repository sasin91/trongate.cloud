<?php

require_once __DIR__ . '/Deploy_source.php';
require_once __DIR__ . '/Follows_branch.php';
require_once __DIR__ . '/../../../git/Git_repo.php';

/**
 * A trongate.cloud git repository, read straight from its bare repository on
 * the platform-git volume
 */
final class Internal_source implements Deploy_source {
    use Follows_branch;

    private function __construct(private readonly Git_repo $repo) {
    }

    /**
     * @param array<string, mixed> $app
     */
    static function for_app(array $app): self {
        $repo = Git_repo::from_url((string) ($app['source_url'] ?? ''));

        if ($repo === null || !$repo->exists()) {
            throw new RuntimeException('The app\'s trongate.cloud git repository was not found');
        }

        return new self($repo);
    }

    function name(): string {
        return $this->repo->name . '.git';
    }

    function commit(string $branch, array $deployment, string $commit_sha, Task_log $log): string {
        $commit_sha = strtolower($commit_sha);

        if (self::follows_branch($deployment, $commit_sha)) {
            $commit_sha = self::branch_head_instead($commit_sha, (string) $this->repo->resolve($branch), $branch, $log);
        }

        $resolved = $this->repo->resolve($commit_sha !== '' ? $commit_sha : $branch);

        if ($resolved === null && $commit_sha === '') {
            throw new RuntimeException("Branch {$branch} has no commits yet");
        }

        return self::full_sha_or_fail((string) $resolved);
    }

    /**
     * From git archive
     */
    function extract(Runtime $runtime, Task_log $log, string $commit_sha, string $work, string $src): void {
        $q = fn(string $value) => Trongate_config::sh_quote($value);
        $tar = "{$work}/source.tar";
        $this->repo->archive($commit_sha, $tar);

        $log->info('Read source from trongate.cloud git (' . round(filesize($tar) / 1024) . ' KiB)');

        if ($runtime->shell('tar -xf ' . $q($tar) . ' -C ' . $q($src) . ' && rm -f ' . $q($tar), $log) !== 0) {
            throw new RuntimeException('Extracting the repository failed');
        }
    }
}
