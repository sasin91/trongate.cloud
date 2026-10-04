<?php

/**
 * GitHub REST client for one token: a customer's user access token (from the
 * GitHub App's sign-in) or an installation token (Github_app::repository_token).
 * https://docs.github.com/en/rest
 */
class Github extends Client {

    protected string $api_base = 'https://api.github.com';

    /** Pages of 100 repositories read per installation */
    const MAX_REPOSITORY_PAGES = 5;

    function __construct(private string $token) {
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

    function url(string $repo): string {
        return "https://github.com/{$repo}";
    }

    function download_url(string $url, string $branch): string {
        $repo = preg_replace('#^https?://[^/]+/#', '', rtrim($url, '/'));

        return "https://api.github.com/repos/{$repo}/tarball/{$branch}";
    }

    function headers(): array {
        return [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.github+json',
            'User-Agent: TrongateCloud/1.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
    }
}
