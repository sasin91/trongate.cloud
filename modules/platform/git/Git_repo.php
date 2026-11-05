<?php

/**
 * One internal git repository: a bare repository under PLATFORM_GIT_ROOT for an
 * app built from trongate.cloud git (app.source_provider 'internal').
 *
 * The repository is named after the app that created it, p{app_id}.git, and
 * the app's source_url is its clone address, {BASE_URL}git/p{app_id}.git. A
 * staging app copies that source_url (split_staging), so it builds from the
 * same repository, another branch.
 *
 * The panel creates repositories and serves clone and push (modules/git); the
 * worker reads commits for deploys (deploy task). Every git command runs
 * without a shell, with its arguments as a list.
 */
final class Git_repo {

    const PROVIDER = 'internal';

    /** Repository names: p{app_id} */
    const NAME_PATTERN = '/^p[1-9][0-9]{0,9}$/';

    /** A source_url that names an internal repository, e.g. https://trongate.cloud/git/p12.git */
    const URL_PATTERN = '#/git/(p[1-9][0-9]{0,9})\.git$#';

    private function __construct(public readonly string $name, private readonly string $root) {}

    static function for_app(int $app_id, string $root = PLATFORM_GIT_ROOT): self {
        if ($app_id < 1) {
            throw new InvalidArgumentException('Invalid app id');
        }

        return new self('p' . $app_id, $root);
    }

    /**
     * The repository a clone address names, or null for any other address
     */
    static function from_url(string $source_url, string $root = PLATFORM_GIT_ROOT): ?self {
        if (!preg_match(self::URL_PATTERN, rtrim($source_url, '/'), $match)) {
            return null;
        }

        return new self($match[1], $root);
    }

    /**
     * The repository a request path names (p12.git), or null
     */
    static function from_path_name(string $path_name, string $root = PLATFORM_GIT_ROOT): ?self {
        if (!str_ends_with($path_name, '.git')) {
            return null;
        }

        $name = substr($path_name, 0, -4);

        if (!preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        return new self($name, $root);
    }

    /**
     * The clone address that becomes the app's source_url
     */
    static function clone_url(string $base_url, int $app_id): string {
        return rtrim($base_url, '/') . '/git/p' . $app_id . '.git';
    }

    /**
     * The app that created the repository
     */
    function app_id(): int {
        return (int) substr($this->name, 1);
    }

    function root(): string {
        return $this->root;
    }

    function path(): string {
        return $this->root . '/' . $this->name . '.git';
    }

    function exists(): bool {
        return is_file($this->path() . '/HEAD');
    }

    /**
     * Create the bare repository, with main as its default branch. Pushes are
     * checked object by object (receive.fsckObjects), and nothing can push a
     * ref outside refs/heads or refs/tags (receive.hideRefs keeps the rest out
     * of sight).
     */
    function create(): void {
        if ($this->exists()) {
            return;
        }

        if (!is_dir($this->root) && !mkdir($this->root, 0775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Could not create the git root');
        }

        $init = self::git(['init', '--bare', '--quiet', '--initial-branch=main', $this->path()]);

        if ($init['code'] !== 0) {
            throw new RuntimeException('git init failed: ' . trim($init['err']));
        }

        $settings = [
            'receive.fsckObjects' => 'true',
            'receive.denyDeletes' => 'false',
            'http.receivepack' => 'true',
            'http.uploadpack' => 'true',
            'gc.auto' => '256',
        ];

        foreach ($settings as $key => $value) {
            $this->run_or_fail(['config', $key, $value]);
        }
    }

    /**
     * Commit SHA of each branch, by name
     *
     * @return array<string, string>
     */
    function branch_heads(): array {
        $result = $this->run(['for-each-ref', '--format=%(objectname) %(refname)', 'refs/heads/']);

        if ($result['code'] !== 0) {
            return [];
        }

        $heads = [];

        foreach (explode("\n", trim($result['out'])) as $line) {
            if (!preg_match('#^([0-9a-f]{40}) refs/heads/(.+)$#', $line, $match)) {
                continue;
            }

            $heads[$match[2]] = $match[1];
        }

        return $heads;
    }

    /**
     * The branches whose head differs between two branch_heads() snapshots, with
     * their new SHA; a deleted branch is left out
     *
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return array<string, string>
     */
    static function moved_branches(array $before, array $after): array {
        $moved = [];

        foreach ($after as $branch => $sha) {
            if (($before[$branch] ?? null) !== $sha) {
                $moved[$branch] = $sha;
            }
        }

        return $moved;
    }

    /**
     * Full SHA of a commit: a branch name, or a 7 to 40 character SHA. Null
     * when there is no such commit.
     */
    function resolve(string $ref): ?string {
        if (!self::is_safe_ref($ref)) {
            return null;
        }

        $candidate = preg_match('/^[0-9a-f]{7,40}$/i', $ref) ? strtolower($ref) : 'refs/heads/' . $ref;
        $result = $this->run(['rev-parse', '--verify', '--quiet', '--end-of-options', $candidate . '^{commit}']);

        if ($result['code'] !== 0) {
            return null;
        }

        $sha = trim($result['out']);

        return preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : null;
    }

    /**
     * The commit's files as a tar file at $tar_path (git archive)
     */
    function archive(string $sha, string $tar_path): void {
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new InvalidArgumentException('Invalid commit SHA');
        }

        $this->run_or_fail(['archive', '--format=tar', '-o', $tar_path, $sha]);
    }

    /**
     * Branch names and SHAs only: no option-like names, no ref syntax tricks
     */
    static function is_safe_ref(string $ref): bool {
        if ($ref === '' || strlen($ref) > 200 || str_starts_with($ref, '-') || str_contains($ref, '..')) {
            return false;
        }

        return (bool) preg_match('#^[A-Za-z0-9._/-]+$#', $ref);
    }

    /**
     * Run git on this repository. The panel's and the worker's users differ,
     * so git is told the repository is safe to read whoever owns it.
     *
     * @param list<string> $args
     * @return array{code: int, out: string, err: string}
     */
    function run(array $args): array {
        return self::git(['--git-dir=' . $this->path(), ...$args]);
    }

    /**
     * Run git with these arguments, without a shell
     *
     * @param list<string> $args
     * @return array{code: int, out: string, err: string}
     */
    static function git(array $args): array {
        $command = ['git', '-c', 'safe.directory=*', ...$args];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::environment());

        if (!is_resource($process)) {
            return ['code' => 127, 'out' => '', 'err' => 'git could not be started'];
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    /**
     * What every git command runs with: no user or system config, no prompts
     */
    static function environment(): array {
        return [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => sys_get_temp_dir(),
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ];
    }

    /**
     * @param list<string> $args
     */
    private function run_or_fail(array $args): string {
        $result = $this->run($args);

        if ($result['code'] !== 0) {
            throw new RuntimeException('git ' . $args[0] . ' failed: ' . trim($result['err']));
        }

        return $result['out'];
    }
}
