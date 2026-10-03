<?php

/**
 * GitHub Source Control Provider
 * https://docs.github.com/en/rest
 */
class Github extends Client {

    protected string $api_base = 'https://api.github.com';

    function __construct(private string $token) {
    }

    function validate_credentials(): bool {
        if (empty($this->token)) {
            return false;
        }
        try {
            $this->request('GET', '/user');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    function list_repositories(?string $owner = null): array {
        $endpoint = $owner ? "/users/{$owner}/repos" : '/user/repos';
        $response = $this->request('GET', $endpoint . '?per_page=100&sort=updated');

        $repos = [];
        foreach ($response as $repo) {
            $repos[] = [
                'id' => (string) $repo['id'],
                'name' => $repo['name'],
                'full_name' => $repo['full_name'],
                'url' => $repo['html_url'],
                'private' => $repo['private'],
            ];
        }

        return $repos;
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
