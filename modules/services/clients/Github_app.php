<?php

require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/../../../config/platform.php';

/**
 * The trongate.cloud GitHub App, framework-free so the worker can load it.
 *
 * - As an OAuth client (client id + secret): authorize_url(), exchange_code()
 *   and refresh() give user access tokens, used with the Github client to
 *   sign customers in and list the repositories they can pick.
 * - As the App (client id + private key, a short-lived RS256 JWT):
 *   repository_token() mints an installation token that reads one
 *   repository's contents, for builds and the deploy reconcile.
 *
 * https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app
 */
class Github_app extends Client {

    protected string $api_base = 'https://api.github.com';

    const OAUTH_BASE = 'https://github.com/login/oauth';

    /** JWT lifetime; GitHub allows at most 10 minutes */
    const JWT_TTL_SECONDS = 540;

    function __construct(
        private string $client_id,
        private string $client_secret = '',
        private string $private_key = '',
        private string $slug = '',
    ) {
    }

    static function from_env(): self {
        return new self(GITHUB_APP_CLIENT_ID, GITHUB_APP_CLIENT_SECRET, GITHUB_APP_PRIVATE_KEY, GITHUB_APP_SLUG);
    }

    /**
     * Whether sign-in (OAuth) is configured
     */
    function can_sign_in(): bool {
        return $this->client_id !== '' && $this->client_secret !== '';
    }

    // --- OAuth (user access tokens) ------------------------------------------

    function authorize_url(string $state, string $redirect_uri): string {
        return self::OAUTH_BASE . '/authorize?' . http_build_query([
            'client_id' => $this->client_id,
            'redirect_uri' => $redirect_uri,
            'state' => $state,
            'allow_signup' => 'true',
        ]);
    }

    /**
     * Where a customer installs the App on their account or organization
     */
    function install_url(): string {
        return 'https://github.com/apps/' . rawurlencode($this->slug) . '/installations/new';
    }

    /**
     * Trade an OAuth callback code for a user access token.
     *
     * @return array{token: string, expires_at: ?int, refresh_token: ?string, refresh_expires_at: ?int}
     * @throws Client_Error when GitHub refuses the code
     */
    function exchange_code(string $code, string $redirect_uri): array {
        return $this->oauth_token(['code' => $code, 'redirect_uri' => $redirect_uri]);
    }

    /**
     * Renew an expired user access token (when the App expires them, the default).
     *
     * @return array{token: string, expires_at: ?int, refresh_token: ?string, refresh_expires_at: ?int}
     * @throws Client_Error when the refresh token is expired or revoked
     */
    function refresh(string $refresh_token): array {
        return $this->oauth_token(['grant_type' => 'refresh_token', 'refresh_token' => $refresh_token]);
    }

    /**
     * POST login/oauth/access_token. GitHub answers 200 with an "error" field on failure.
     */
    function oauth_token(array $fields): array {
        $response = $this->_request('POST', self::OAUTH_BASE . '/access_token', [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            ...$fields,
        ], ['Accept: application/json']);

        if (empty($response['access_token'])) {
            $error = (string) ($response['error_description'] ?? $response['error'] ?? 'no access token');
            throw new Client_Error("GitHub sign-in failed: {$error}", 401);
        }

        $now = time();

        return [
            'token' => (string) $response['access_token'],
            'expires_at' => isset($response['expires_in']) ? $now + (int) $response['expires_in'] : null,
            'refresh_token' => isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            'refresh_expires_at' => isset($response['refresh_token_expires_in']) ? $now + (int) $response['refresh_token_expires_in'] : null,
        ];
    }

    // --- App (installation tokens) -------------------------------------------

    /**
     * An installation token that can read one repository's contents (about an
     * hour). Fails with 404 when the App is not installed on the repository.
     *
     * @param string $repo owner/name
     * @throws Client_Error
     */
    function repository_token(string $repo): string {
        $installation = $this->request('GET', "/repos/{$repo}/installation");
        $installation_id = (int) ($installation['id'] ?? 0);

        if ($installation_id <= 0) {
            throw new Client_Error("No GitHub App installation for {$repo}", 404);
        }

        $response = $this->request('POST', "/app/installations/{$installation_id}/access_tokens", [
            'repositories' => [explode('/', $repo, 2)[1]],
            'permissions' => ['contents' => 'read'],
        ]);

        $token = (string) ($response['token'] ?? '');

        if ($token === '') {
            throw new Client_Error("GitHub returned no installation token for {$repo}", 500);
        }

        return $token;
    }

    /**
     * The App's JWT (RS256, issuer = client id)
     */
    function jwt(): string {
        if ($this->client_id === '' || $this->private_key === '') {
            throw new RuntimeException('GITHUB_APP_CLIENT_ID and GITHUB_APP_PRIVATE_KEY are required to read repositories');
        }

        $now = time();
        $segments = [
            self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            // Backdated a minute for clock drift
            self::base64url(json_encode(['iat' => $now - 60, 'exp' => $now + self::JWT_TTL_SECONDS, 'iss' => $this->client_id])),
        ];

        $key = openssl_pkey_get_private($this->private_key);

        if ($key === false || !openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('GITHUB_APP_PRIVATE_KEY is not a valid private key');
        }

        $segments[] = self::base64url($signature);

        return implode('.', $segments);
    }

    static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * API requests authenticate as the App (OAuth requests go through _request without it)
     */
    function request(string $method, string $endpoint, array $data = [], array $headers = []): array {
        return parent::request($method, $endpoint, $data, ['Authorization: Bearer ' . $this->jwt(), ...$headers]);
    }

    function headers(): array {
        return [
            'Accept: application/vnd.github+json',
            'User-Agent: TrongateCloud/1.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
    }
}
