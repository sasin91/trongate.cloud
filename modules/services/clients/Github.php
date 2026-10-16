<?php

/**
 * GitHub REST client for one token: a customer's user access token (from the
 * GitHub App's sign-in) or an installation token (Github_app::repository_token).
 * Without a token (anonymous()) it reads public repositories only, within
 * GitHub's limit for unsigned requests (60 an hour per IP address).
 * https://docs.github.com/en/rest
 */
class Github extends Client {

    protected string $api_base = 'https://api.github.com';

    /** Pages of 100 repositories read per installation */
    const MAX_REPOSITORY_PAGES = 5;

    function __construct(private string $token) {
    }

    /**
     * A client without a token, for apps deployed from a public link
     */
    static function anonymous(): self {
        return new self('');
    }

    /**
     * owner/name from a pasted link to a GitHub repository, or null when it
     * is not one. Takes https://github.com/owner/name, with or without
     * https://, a trailing .git or slash, or a page inside it (/tree/main).
     * Only owner/name is kept: requests always go to api.github.com.
     */
    static function repository_from_link(string $link): ?string {
        $link = trim($link);

        if (!preg_match('~^(?:https?://)?(?:www\.)?github\.com/([A-Za-z0-9-]{1,39})/([A-Za-z0-9_.-]{1,100})(?:[/?#].*)?$~i', $link, $match)) {
            return null;
        }

        $name = preg_replace('/\.git$/i', '', $match[2]);

        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        return $match[1] . '/' . $name;
    }

    /**
     * Whether the repository exists and is public. A private one this token
     * can see is not; one it cannot see fails with a 404 Client_Error.
     */
    function is_public(string $repo): bool {
        $repository = $this->request('GET', "/repos/{$repo}");

        return empty($repository['private']);
    }

    /**
     * The signed-in GitHub user: id, login, ...
     */
    function user(): array {
        return $this->request('GET', '/user');
    }

    /**
     * The user's verified primary email, or null (needs the App's "Email addresses" permission)
     */
    function primary_email(): ?string {
        foreach ($this->request('GET', '/user/emails?per_page=100') as $email) {
            if (!empty($email['primary']) && !empty($email['verified']) && is_string($email['email'] ?? null)) {
                return $email['email'];
            }
        }

        return null;
    }

    /**
     * Repositories the user may pick: those of the App's installations that
     * the user can access (a user access token only sees those).
     *
     * @return list<array{id: string, name: string, full_name: string, url: string, private: bool}>
     */
    function list_repositories(): array {
        $installations = $this->request('GET', '/user/installations?per_page=100')['installations'] ?? [];
        $repos = [];

        foreach ($installations as $installation) {
            $id = (int) ($installation['id'] ?? 0);

            for ($page = 1; $page <= self::MAX_REPOSITORY_PAGES; $page++) {
                $response = $this->request('GET', "/user/installations/{$id}/repositories?per_page=100&page={$page}");

                foreach ($response['repositories'] ?? [] as $repo) {
                    $repos[strtolower($repo['full_name'])] = [
                        'id' => (string) $repo['id'],
                        'name' => $repo['name'],
                        'full_name' => $repo['full_name'],
                        'url' => $repo['html_url'],
                        'private' => (bool) $repo['private'],
                    ];
                }

                if (count($response['repositories'] ?? []) < 100) {
                    break;
                }
            }
        }

        uasort($repos, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));

        return array_values($repos);
    }

    /**
     * Whether the user may pick this repository (owner/name, case-insensitive)
     */
    function can_pick(string $repo): bool {
        foreach ($this->list_repositories() as $candidate) {
            if (strcasecmp($candidate['full_name'], $repo) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * List branches for a repository
     *
     * @return list<array{name: string, commit: array{sha: string, url: string}, protected: bool, protection_url?: string}>
     */
    function list_branches(string $repo, int $per_page = 5): array {
        return $this->request('GET', "/repos/{$repo}/branches?per_page={$per_page}");
    }

    /**
     * The PHP files directly in config/ at a branch: file name => source.
     * No config/ folder gives none; files over $max_bytes and any past
     * $max_files are left out.
     *
     * @return array<string, string>
     */
    function config_php_files(string $repo, string $branch, int $max_files, int $max_bytes): array {
        try {
            $entries = $this->request('GET', "/repos/{$repo}/contents/config?ref=" . rawurlencode($branch));
        } catch (Client_Error $e) {
            if ($e->getCode() === 404) {
                return [];
            }

            throw $e;
        }

        // config is a file, not a folder
        if (!array_is_list($entries)) {
            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if (count($files) >= $max_files) {
                break;
            }

            $is_php_file = ($entry['type'] ?? '') === 'file' && str_ends_with((string) ($entry['name'] ?? ''), '.php');

            if ($is_php_file && (int) ($entry['size'] ?? 0) <= $max_bytes) {
                $files[(string) $entry['name']] = $this->blob($repo, (string) $entry['sha']);
            }
        }

        return $files;
    }

    /**
     * One file's content at a commit or branch, or null when there is no
     * file at that path. Files over 1 MB come back as null too: GitHub's
     * contents API leaves their content out.
     */
    function file_at(string $repo, string $ref, string $path): ?string {
        $url = "/repos/{$repo}/contents/" . str_replace('%2F', '/', rawurlencode($path)) . '?ref=' . rawurlencode($ref);

        try {
            $file = $this->request('GET', $url);
        } catch (Client_Error $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        if (($file['type'] ?? '') !== 'file' || ($file['encoding'] ?? '') !== 'base64') {
            return null;
        }

        $content = base64_decode((string) ($file['content'] ?? ''), true);

        if ($content === false) {
            throw new RuntimeException("GitHub returned an unreadable {$path} of {$repo}");
        }

        return $content;
    }

    // --- Git data and pull requests (Trongate upgrades) ------------------------

    /**
     * Every file of a commit, tag or branch: path => blob SHA (symlinks and
     * submodules left out)
     *
     * @return array<string, string>
     * @throws RuntimeException when GitHub truncates the tree
     */
    function tree(string $repo, string $ref): array {
        $tree = $this->request('GET', "/repos/{$repo}/git/trees/" . rawurlencode($ref) . '?recursive=1');

        if (!empty($tree['truncated'])) {
            throw new RuntimeException("{$repo} has too many files for GitHub to list at {$ref}");
        }

        $files = [];

        foreach ($tree['tree'] ?? [] as $entry) {
            if (($entry['type'] ?? '') === 'blob' && ($entry['mode'] ?? '') !== '120000') {
                $files[(string) $entry['path']] = (string) $entry['sha'];
            }
        }

        return $files;
    }

    /**
     * A blob's content
     */
    function blob(string $repo, string $sha): string {
        $blob = $this->request('GET', "/repos/{$repo}/git/blobs/{$sha}");
        $content = base64_decode((string) ($blob['content'] ?? ''), true);

        if ($content === false) {
            throw new RuntimeException("GitHub returned an unreadable blob {$sha} of {$repo}");
        }

        return $content;
    }

    /**
     * Commit file changes on top of a commit, without a checkout: path =>
     * new content, or null to delete. Returns the new commit's SHA.
     *
     * @param array<string, ?string> $changes
     */
    function commit_files(string $repo, string $parent_sha, array $changes, string $message): string {
        $parent = $this->request('GET', "/repos/{$repo}/git/commits/{$parent_sha}");
        $entries = [];

        foreach ($changes as $path => $content) {
            $sha = null;

            if ($content !== null) {
                $sha = $this->request('POST', "/repos/{$repo}/git/blobs", ['content' => base64_encode($content), 'encoding' => 'base64'])['sha'];
            }

            $entries[] = ['path' => (string) $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $sha];
        }

        $tree = $this->request('POST', "/repos/{$repo}/git/trees", ['base_tree' => $parent['tree']['sha'], 'tree' => $entries]);
        $commit = $this->request('POST', "/repos/{$repo}/git/commits", [
            'message' => $message,
            'tree' => $tree['sha'],
            'parents' => [$parent_sha],
        ]);

        return (string) $commit['sha'];
    }

    /**
     * The commit SHA a branch points at, or null when there is no such branch
     */
    function branch_sha(string $repo, string $branch): ?string {
        try {
            return (string) $this->request('GET', "/repos/{$repo}/git/ref/heads/" . rawurlencode($branch))['object']['sha'];
        } catch (Client_Error $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    function create_branch(string $repo, string $branch, string $sha): void {
        $this->request('POST', "/repos/{$repo}/git/refs", ['ref' => "refs/heads/{$branch}", 'sha' => $sha]);
    }

    /**
     * The open pull request from $head (a branch of the same repository), if any
     */
    function open_pull_request(string $repo, string $head): ?array {
        $owner = explode('/', $repo, 2)[0];
        $pulls = $this->request('GET', "/repos/{$repo}/pulls?state=open&head=" . rawurlencode("{$owner}:{$head}"));

        return $pulls[0] ?? null;
    }

    /**
     * Open a draft pull request; returns GitHub's pull request (number, html_url, ...)
     */
    function create_pull_request(string $repo, string $head, string $base, string $title, string $body): array {
        $pull = ['title' => $title, 'head' => $head, 'base' => $base, 'body' => $body, 'draft' => true];

        try {
            return $this->request('POST', "/repos/{$repo}/pulls", $pull);
        } catch (Client_Error $e) {
            // Private repositories on GitHub Free have no draft pull requests
            if ($e->getCode() !== 422 || !str_contains(strtolower($e->getMessage()), 'draft')) {
                throw $e;
            }

            return $this->request('POST', "/repos/{$repo}/pulls", ['draft' => false] + $pull);
        }
    }

    function url(string $repo): string {
        return "https://github.com/{$repo}";
    }

    function download_url(string $url, string $branch): string {
        $repo = preg_replace('#^https?://[^/]+/#', '', rtrim($url, '/'));

        return "https://api.github.com/repos/{$repo}/tarball/{$branch}";
    }

    function headers(): array {
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: TrongateCloud/1.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        return $headers;
    }
}
