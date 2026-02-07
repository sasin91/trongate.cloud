<?php

/**
 * GitHub Source Control Provider
 * https://docs.github.com/en/rest
 */
class Github extends Client {

    private string $api_base = 'https://api.github.com';

    function __construct(private string $token) {}

    // --- Static metadata ---

    static function get_id(): string {
        return 'github';
    }

    static function get_label(): string {
        return 'GitHub';
    }

    static function get_url(): string {
        return 'https://github.com';
    }

    static function get_credential_fields(): array {
        return [
            ['name' => 'token', 'label' => 'Personal Access Token', 'type' => 'password'],
        ];
    }

    // --- Instance methods ---

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

    function get_repository(string $repo): ?array {
        try {
            $response = $this->request('GET', "/repos/{$repo}");
            return [
                'id' => (string) $response['id'],
                'name' => $response['name'],
                'full_name' => $response['full_name'],
                'url' => $response['html_url'],
                'default_branch' => $response['default_branch'],
                'private' => $response['private'],
            ];
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * List branches for a repository
     * 
     * @return array<{ 
     *    name: string, 
     *    commit: { sha: string, url: string }, 
     *    protected: bool, 
     *    protection: { 
     *       enabled: bool, 
     *       required_status_checks: { 
     *          enforcement_level: string, 
     *          contexts: array, 
     *          checks: array 
     *       } 
     *    }, 
     *    protection_url: string
     * }>
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

    function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.github+json',
            'User-Agent: TrongateCloud/1.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
    }

    /**
     * Make API request to GitHub
     */
    function request(string $method, string $endpoint, array $data = []): array {
        $url = $this->api_base . $endpoint;

        return $this->_request($method, $url, $data);
    }
}
