<?php

require_once APPPATH . 'modules/mcp/providers/Mcp_provider.php';

/**
 * Child module of mcp: the OAuth 2.1 authorization server that lets a
 * customer connect an AI assistant (an Mcp_provider, e.g. Claude) to the MCP
 * endpoint, as the MCP authorization spec asks for:
 *
 *   .well-known/oauth-protected-resource      where to authorize (RFC 9728)
 *   .well-known/oauth-authorization-server    the endpoints below (RFC 8414)
 *   oauth/register                             dynamic client registration (RFC 7591)
 *   oauth/authorize                            start: checks the request, then the consent page
 *   oauth/consent                              sign in and Allow / Cancel
 *   oauth/token                                code (with PKCE S256) or refresh token => tokens
 *   oauth/revoke                               token revocation (RFC 7009)
 *
 * Provider-neutral: a provider only decides which redirect URIs it owns.
 * The pending request rides in an HMAC-signed cookie (APP_KEY), like the
 * GitHub sign-in state, so the customer can sign in with GitHub on the way.
 *
 * @property-read Oauth_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Validation $validation
 */
class Oauth extends Trongate {

    const SCOPE = 'mcp';

    const REQUEST_COOKIE = 'tc_mcp_authorize';

    /** Seconds the customer has to sign in and decide */
    const REQUEST_TTL_SECONDS = 600;

    /** config/login.php, user level 2 */
    const CUSTOMER_LEVEL = 2;

    const MAX_REDIRECT_URIS = 10;

    /**
     * GET .well-known/oauth-protected-resource(/mcp)
     */
    function protected_resource(): void {
        $this->_send_json(200, [
            'resource' => self::resource(),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'trongate.cloud',
        ]);
    }

    /**
     * GET .well-known/oauth-authorization-server (and openid-configuration, which some clients try first)
     */
    function metadata(): void {
        $this->_send_json(200, [
            'issuer' => self::issuer(),
            'authorization_endpoint' => BASE_URL . 'oauth/authorize',
            'token_endpoint' => BASE_URL . 'oauth/token',
            'registration_endpoint' => BASE_URL . 'oauth/register',
            'revocation_endpoint' => BASE_URL . 'oauth/revoke',
            'scopes_supported' => [self::SCOPE],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    /**
     * POST oauth/register: JSON client metadata in, client_id (and a secret for confidential clients) out
     */
    function register(): void {
        $this->_preflight();

        if (REQUEST_TYPE !== 'POST') {
            $this->_send_json(405, ['error' => 'invalid_request', 'error_description' => 'POST client metadata as JSON']);
        }

        try {
            $metadata = post();
        } catch (Exception $e) {
            $metadata = null;
        }

        if (!is_array($metadata)) {
            $this->_oauth_error(400, 'invalid_client_metadata', 'The body must be a JSON object');
        }

        $uris = $metadata['redirect_uris'] ?? null;

        if (!is_array($uris) || $uris === [] || count($uris) > self::MAX_REDIRECT_URIS || !array_is_list($uris)
            || array_filter($uris, fn($uri) => !is_string($uri) || strlen($uri) > 500) !== []) {
            $this->_oauth_error(400, 'invalid_redirect_uri', 'redirect_uris must list 1 to ' . self::MAX_REDIRECT_URIS . ' URIs');
        }

        $provider = Mcp_providers::for_redirect_uris($uris);

        if ($provider === null) {
            $this->_oauth_error(400, 'invalid_redirect_uri', 'These redirect URIs belong to no supported assistant');
        }

        $auth_method = (string) ($metadata['token_endpoint_auth_method'] ?? 'none');

        if (!in_array($auth_method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            $this->_oauth_error(400, 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method');
        }

        foreach (['grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']] as $field => $supported) {
            $asked = $metadata[$field] ?? $supported;

            if (!is_array($asked) || array_diff($asked, $supported) !== []) {
                $this->_oauth_error(400, 'invalid_client_metadata', "Unsupported {$field}");
            }
        }

        if ($this->model->too_many_registrations()) {
            $this->_oauth_error(429, 'temporarily_unavailable', 'Too many registrations, try again later');
        }

        $this->model->prune();

        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($metadata['client_name'] ?? '')) ?? '');
        $name = $name === '' ? $provider->name() : mb_substr($name, 0, 200);
        $secret = $auth_method === 'none' ? null : 'tcs_' . bin2hex(random_bytes(32));
        $client_id = $this->model->register_client($provider->key(), $name, $uris, $secret);

        $this->_send_json(201, array_filter([
            'client_id' => $client_id,
            'client_secret' => $secret,
            'client_id_issued_at' => time(),
            'client_secret_expires_at' => $secret === null ? null : 0,
            'client_name' => $name,
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $auth_method,
            'scope' => self::SCOPE,
        ], fn($value) => $value !== null));
    }

    /**
     * GET oauth/authorize: check the request, remember it, then the consent page.
     * Without a known client and one of its redirect URIs nothing is redirected
     * anywhere; other mistakes go back to the client as OAuth errors.
     */
    function authorize(): void {
        $client = $this->model->find_client((string) ($_GET['client_id'] ?? ''));
        $redirect_uri = (string) ($_GET['redirect_uri'] ?? '');
        $state = isset($_GET['state']) ? (string) $_GET['state'] : null;

        if ($client === false) {
            $this->_error_page('This assistant is not registered with trongate.cloud. Connect it again from its own settings.');
        }

        $uris = json_decode((string) $client->redirect_uris, true) ?: [];

        if ($redirect_uri === '' && count($uris) === 1) {
            $redirect_uri = $uris[0];
        }

        if (!in_array($redirect_uri, $uris, true)) {
            $this->_error_page('The assistant asked to send you somewhere it did not register.');
        }

        $back = fn(string $error, string $description) => $this->_redirect_back($redirect_uri, $state, ['error' => $error, 'error_description' => $description]);

        if (($_GET['response_type'] ?? '') !== 'code') {
            $back('unsupported_response_type', 'Only response_type=code is supported');
        }

        $challenge = (string) ($_GET['code_challenge'] ?? '');

        if (($_GET['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $challenge)) {
            $back('invalid_request', 'PKCE with code_challenge_method=S256 is required');
        }

        $resource = (string) ($_GET['resource'] ?? '');

        if ($resource !== '' && rtrim($resource, '/') !== rtrim(self::resource(), '/')) {
            $back('invalid_target', 'The only resource here is ' . self::resource());
        }

        $this->_set_cookie(self::REQUEST_COOKIE, $this->_sign([
            'client_id' => (string) $client->client_id,
            'redirect_uri' => $redirect_uri,
            'state' => $state,
            'code_challenge' => $challenge,
            'expires' => time() + self::REQUEST_TTL_SECONDS,
        ]));

        redirect('oauth/consent');
    }

    /**
     * GET oauth/consent: who is asking and what for, with Allow and Cancel.
     * Signed out, the customer signs in first (GitHub brings them back here).
     */
    function consent(): void {
        $request = $this->_pending_request();
        $client = $request === null ? false : $this->model->find_client($request['client_id']);

        if ($request === null || $client === false) {
            $this->_error_page('This request has expired. Start connecting again from your assistant.');
        }

        $customer = $this->trongate_tokens->attempt_get_valid_token(self::CUSTOMER_LEVEL) === false
            ? false
            : $this->model->_get_current_customer();

        $provider = Mcp_providers::find((string) $client->provider);

        $this->view('consent', [
            'view_module' => 'mcp/oauth',
            'customer' => $customer,
            'onboarded' => $customer !== false && $customer->onboarded_at !== null,
            'provider_name' => $provider?->name() ?? (string) $client->provider,
            'client_name' => (string) $client->client_name,
            'redirect_host' => $this->_redirect_host($request['redirect_uri']),
            'form_location' => BASE_URL . 'oauth/consent/submit',
        ]);
    }

    /**
     * POST oauth/consent/submit: decision=allow sends a code back, anything else access_denied
     */
    function submit_consent(): void {
        if (REQUEST_TYPE !== 'POST') {
            http_response_code(405);
            die('Method not allowed');
        }

        $this->validation->run(); // CSRF

        $request = $this->_pending_request();
        $client = $request === null ? false : $this->model->find_client($request['client_id']);

        if ($request === null || $client === false) {
            $this->_error_page('This request has expired. Start connecting again from your assistant.');
        }

        $this->_set_cookie(self::REQUEST_COOKIE, '', -1);

        if (post('decision') !== 'allow') {
            $this->_redirect_back($request['redirect_uri'], $request['state'], ['error' => 'access_denied', 'error_description' => 'The customer said no']);
        }

        $customer = $this->trongate_tokens->attempt_get_valid_token(self::CUSTOMER_LEVEL) === false
            ? false
            : $this->model->_get_current_customer();

        if ($customer === false || $customer->onboarded_at === null) {
            redirect('oauth/consent');
        }

        $grant_id = $this->model->grant_for((int) $client->id, (int) $customer->id, self::SCOPE);
        $code = $this->model->issue_token($grant_id, 'code', $request['code_challenge'], $request['redirect_uri']);

        $this->_redirect_back($request['redirect_uri'], $request['state'], ['code' => $code]);
    }

    /**
     * POST oauth/token (form encoded): authorization_code or refresh_token
     */
    function token(): void {
        $this->_preflight();

        if (REQUEST_TYPE !== 'POST') {
            $this->_oauth_error(405, 'invalid_request', 'POST only');
        }

        $grant_type = (string) post('grant_type');

        $row = match ($grant_type) {
            'authorization_code' => $this->model->spend_token('code', (string) post('code')),
            'refresh_token' => $this->model->spend_token('refresh', (string) post('refresh_token')),
            default => $this->_oauth_error(400, 'unsupported_grant_type', 'Use authorization_code or refresh_token'),
        };

        if ($row === false) {
            $this->_oauth_error(400, 'invalid_grant', 'The code or refresh token is invalid, expired or already used');
        }

        [$client_id, $client_secret] = $this->_client_credentials();

        if ($client_id !== (string) $row->client_id) {
            $this->_oauth_error(400, 'invalid_grant', 'Issued to another client');
        }

        if ($row->client_secret_hash !== null && !hash_equals((string) $row->client_secret_hash, hash('sha256', $client_secret))) {
            $this->_oauth_error(401, 'invalid_client', 'Client authentication failed');
        }

        if ($grant_type === 'authorization_code') {
            $verifier = (string) post('code_verifier');
            $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

            if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !hash_equals((string) $row->code_challenge, $expected)) {
                $this->_oauth_error(400, 'invalid_grant', 'code_verifier does not match the code_challenge');
            }

            if (post('redirect_uri') !== '' && post('redirect_uri') !== (string) $row->redirect_uri) {
                $this->_oauth_error(400, 'invalid_grant', 'redirect_uri differs from the one authorized');
            }
        }

        $grant_id = (int) $row->grant_id;

        $this->_send_json(200, [
            'access_token' => $this->model->issue_token($grant_id, 'access'),
            'token_type' => 'Bearer',
            'expires_in' => Oauth_model::ACCESS_SECONDS,
            'refresh_token' => $this->model->issue_token($grant_id, 'refresh'),
            'scope' => (string) $row->scope,
        ]);
    }

    /**
     * POST oauth/revoke: always 200 (RFC 7009); a refresh token revokes its whole grant
     */
    function revoke(): void {
        $this->_preflight();

        if (REQUEST_TYPE === 'POST') {
            $row = $this->model->spend_token('refresh', (string) post('token'));

            if ($row !== false) {
                $this->model->revoke_grant((int) $row->customer_id, (int) $row->grant_id);
            }
        }

        $this->_send_json(200, []);
    }

    // --- Helpers ---

    static function issuer(): string {
        return rtrim(BASE_URL, '/');
    }

    static function resource(): string {
        return BASE_URL . 'mcp';
    }

    /**
     * client_id and client_secret from HTTP Basic or the form
     *
     * @return array{string, string}
     */
    function _client_credentials(): array {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

        if (preg_match('/^Basic\s+(\S+)$/i', $header, $matches)) {
            $decoded = (string) base64_decode($matches[1], true);

            if (str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);

                return [urldecode($id), urldecode($secret)];
            }
        }

        return [(string) post('client_id'), (string) post('client_secret')];
    }

    /**
     * The request authorize() remembered, while it is fresh
     *
     * @return array{client_id: string, redirect_uri: string, state: ?string, code_challenge: string, expires: int}|null
     */
    function _pending_request(): ?array {
        $request = $this->_verify((string) ($_COOKIE[self::REQUEST_COOKIE] ?? ''));

        if ($request === null || (int) ($request['expires'] ?? 0) < time()) {
            return null;
        }

        return $request;
    }

    function _sign(array $data): string {
        $payload = rtrim(strtr(base64_encode((string) json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        return $payload . '.' . hash_hmac('sha256', 'mcp-authorize:' . $payload, $this->_key());
    }

    function _verify(string $value): ?array {
        $parts = explode('.', $value);

        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', 'mcp-authorize:' . $parts[0], $this->_key()), $parts[1])) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);

        return is_array($data) ? $data : null;
    }

    function _key(): string {
        if (APP_KEY === '') {
            throw new RuntimeException('APP_KEY is required to connect assistants');
        }

        return APP_KEY;
    }

    /**
     * SameSite=Lax: the steps of the flow are top-level GETs and our own form POST
     */
    function _set_cookie(string $name, string $value, int $ttl = self::REQUEST_TTL_SECONDS): void {
        setcookie($name, $value, [
            'expires' => time() + $ttl,
            'path' => '/',
            'secure' => str_starts_with(BASE_URL, 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    function _redirect_host(string $uri): string {
        $parts = parse_url($uri);
        $host = (string) ($parts['host'] ?? '');

        return Mcp_providers::is_loopback($uri) ? 'an app on this computer' : $host;
    }

    /**
     * Back to the client's redirect URI with $params, state and iss (RFC 9207)
     */
    function _redirect_back(string $redirect_uri, ?string $state, array $params): never {
        if ($state !== null) {
            $params['state'] = $state;
        }

        $params['iss'] = self::issuer();
        $separator = str_contains($redirect_uri, '?') ? '&' : '?';

        header('Location: ' . $redirect_uri . $separator . http_build_query($params), true, 302);
        die();
    }

    function _error_page(string $message): never {
        http_response_code(400);
        $this->view('error', ['view_module' => 'mcp/oauth', 'message' => $message]);
        die();
    }

    /**
     * CORS for browser-based clients (no cookies are used on these endpoints)
     */
    function _preflight(): void {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version');

        if (REQUEST_TYPE === 'OPTIONS') {
            http_response_code(204);
            die();
        }
    }

    function _oauth_error(int $status, string $error, string $description): never {
        $this->_send_json($status, ['error' => $error, 'error_description' => $description]);
    }

    function _send_json(int $status, array $payload): never {
        if (!headers_sent()) {
            header('Access-Control-Allow-Origin: *');
        }

        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($payload === [] ? new stdClass() : $payload, JSON_UNESCAPED_SLASHES);
        die();
    }
}
