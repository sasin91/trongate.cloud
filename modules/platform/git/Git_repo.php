<?php

require_once __DIR__ . '/../../cli/Process.php';

/**
 * One internal git repository: a bare repository under PLATFORM_GIT_ROOT for an
 * app built from trongate.cloud git (app.source_provider 'internal').
 *
 * Only the platform writes these repositories, from the worker: the
 * import_git task (Move to trongate.cloud git) and, later, project
 * generation. Customers do not clone or push yet; they get an export to
 * GitHub instead.
 *
 * The repository is named after the app that created it, p{app_id}.git, and
 * the app's source_url names it, trongate-git:p{app_id}. A staging app
 * copies that source_url (split_staging), so it builds from the same
 * repository, another branch.
 *
 * Every git command runs without a shell, with its arguments as a list.
 */
final class Git_repo {

    /** Long enough for git archive or a fetch of a big app */
    const TIMEOUT_SECONDS = 300;

    const PROVIDER = 'internal';

    /** A source_url that names an internal repository, e.g. trongate-git:p12 */
    const URL_PATTERN = '/^trongate-git:(p[1-9][0-9]{0,9})$/';

    private function __construct(public readonly string $name, private readonly string $root) {
    }

    static function for_app(int $app_id, string $root = PLATFORM_GIT_ROOT): self {
        if ($app_id < 1) {
            throw new InvalidArgumentException('Invalid app id');
        }

        return new self('p' . $app_id, $root);
    }

    /**
     * The repository a source_url names, or null for any other source
     */
    static function from_url(string $source_url, string $root = PLATFORM_GIT_ROOT): ?self {
        if (!preg_match(self::URL_PATTERN, trim($source_url), $match)) {
            return null;
        }

        return new self($match[1], $root);
    }

    /**
     * What the app's source_url becomes
     */
    function source_url(): string {
        return 'trongate-git:' . $this->name;
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
     * Create the bare repository, with main as its default branch
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

        $this->run_or_fail(['config', 'gc.auto', '256']);
    }

    /**
     * Copy every branch of another repository, with its history, over this
     * repository's branches of the same name. Objects are checked as they
     * arrive (fetch.fsckObjects). An HTTP header for the fetch (a token) goes
     * in through the environment, so it never appears in a command line.
     */
    function fetch_branches(string $url, ?string $http_header = null): void {
        if (!preg_match('#^https://[A-Za-z0-9.-]+/[A-Za-z0-9_./-]+$#', $url)) {
            throw new InvalidArgumentException('Invalid repository address');
        }

        $env = $http_header === null ? [] : [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.extraHeader',
            'GIT_CONFIG_VALUE_0' => $http_header,
        ];

        $result = self::git(['--git-dir=' . $this->path(), '-c', 'fetch.fsckObjects=true', 'fetch', '--quiet', '--no-tags', '--prune', '--', $url, '+refs/heads/*:refs/heads/*'], $env);

        if ($result['code'] !== 0) {
            throw new RuntimeException('git fetch failed: ' . trim($result['err']));
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
     * Run git on this repository. git is told the repository is safe to read
     * whoever owns the volume's files.
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
     * @param array<string, string> $extra_env
     * @return array{code: int, out: string, err: string}
     */
    static function git(array $args, array $extra_env = []): array {
        $process = new Process(['git', '-c', 'safe.directory=*', ...$args], env: $extra_env + self::environment(), timeout: self::TIMEOUT_SECONDS);

        try {
            $code = $process->run();
        } catch (RuntimeException $e) {
            return ['code' => 127, 'out' => '', 'err' => $e->getMessage()];
        }

        return ['code' => $code, 'out' => $process->output(), 'err' => $process->error_output()];
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
