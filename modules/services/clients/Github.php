<?php

/**
 * GitHub Source Control Provider
 * https://docs.github.com/en/rest
 */
class Github
{

    private string $api_base = 'https://api.github.com';

    public function __construct(private string $token) {}

    // --- Static metadata ---

    public static function get_id(): string
    {
        return 'github';
    }

    public static function get_label(): string
    {
        return 'GitHub';
    }

    public static function get_url(): string
    {
        return 'https://github.com';
    }

    public static function get_credential_fields(): array
    {
        return [
            ['name' => 'token', 'label' => 'Personal Access Token', 'type' => 'password'],
        ];
    }

    // --- Instance methods ---

    public function validate_credentials(): bool
    {
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

    public function list_repositories(?string $owner = null): array
    {
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

    public function get_repository(string $repo): ?array
    {
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
     * Make API request to GitHub
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->api_base . $endpoint;

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.github+json',
            'User-Agent: TrongateCloud/1.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            throw new Exception("GitHub API error: {$error}");
        }

        if ($http_code >= 400) {
            $body = json_decode($response, true);
            $message = $body['message'] ?? "HTTP {$http_code}";
            throw new Exception("GitHub API error ({$http_code}): {$message}");
        }

        return json_decode($response, true) ?? [];
    }
}
