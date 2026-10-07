<?php

require_once __DIR__ . '/traits/Mcp_tools.php';
require_once __DIR__ . '/traits/Mcp_database_tools.php';
require_once __DIR__ . '/traits/Mcp_admin_tools.php';

/**
 * MCP server over Streamable HTTP (JSON responses, no SSE). URL: POST /mcp
 *
 * Two kinds of Bearer token:
 * - an OAuth access token (modules/mcp/oauth): a customer connected an
 *   assistant such as Claude, and the tools act as that customer, in the
 *   projects they are a member of, like the control panel does;
 * - the admin secret (MCP_SECRET env var): every project, plus the admin tools.
 *
 * Stateless: an Mcp-Session-Id is issued on initialize but never required.
 * See docs/mcp.md.
 *
 * @property-read Mcp_model $model
 * @property-read Services $services
 * @property-read Secrets $secrets
 */
class Mcp extends Trongate {
    use Mcp_tools;
    use Mcp_database_tools;
    use Mcp_admin_tools;

    /** Newest first; the first entry is offered when the client asks for an unknown version */
    const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26'];

    /** Assumed when no MCP-Protocol-Version header is sent (per spec) */
    const FALLBACK_PROTOCOL_VERSION = '2025-03-26';

    const SERVER_VERSION = '2.0.0';
    const MAX_BODY_BYTES = 1048576;
    const MIN_SECRET_LENGTH = 32;

    // JSON-RPC 2.0 error codes
    const PARSE_ERROR = -32700;
    const INVALID_REQUEST = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS = -32602;
    const INTERNAL_ERROR = -32603;

    /** The customer the tools act for; null with the admin secret */
    private ?object $customer = null;

    function index(): void {
        // Bearer auth only; don't hold the PHP session lock
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (!$this->_origin_allowed()) {
            $this->_send_json(403, $this->_error(null, self::INVALID_REQUEST, 'Origin not allowed'));
        }

        if (REQUEST_TYPE !== 'POST') {
            // No SSE stream and no session termination
            header('Allow: POST');
            $this->_send_json(405, $this->_error(null, self::INVALID_REQUEST, 'Method not allowed'));
        }

        if (!$this->_authenticate($this->_bearer_token())) {
            // Where to get a token (RFC 9728), so clients can start OAuth by themselves
            header('WWW-Authenticate: Bearer realm="trongate.cloud", resource_metadata="' . BASE_URL . '.well-known/oauth-protected-resource/mcp"');
            $this->_send_json(401, $this->_error(null, self::INVALID_REQUEST, 'Missing or invalid token'));
        }

        $version = $this->_header_protocol_version();
        if ($version === false) {
            $this->_send_json(400, $this->_error(null, self::INVALID_REQUEST, 'Unsupported MCP-Protocol-Version. Supported: ' . implode(', ', self::PROTOCOL_VERSIONS)));
        }

        $body = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($body === false || strlen($body) > self::MAX_BODY_BYTES) {
            $this->_send_json(413, $this->_error(null, self::INVALID_REQUEST, 'Request body too large'));
        }

        $message = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->_send_json(400, $this->_error(null, self::PARSE_ERROR, 'Parse error'));
        }

        if (is_array($message) && array_is_list($message)) {
            $this->_handle_batch($message, $version);
        }

        $response = $this->_handle_message($message, $version, true);

        if ($response === null) {
            // Notification or client response: accepted, no body
            http_response_code(202);
            die();
        }

        $this->_send_json(isset($response['error']) && $response['id'] === null ? 400 : 200, $response);
    }

    /**
     * JSON-RPC batches exist only in protocol 2025-03-26.
     */
    function _handle_batch(array $messages, string $version): void {
        if ($version !== '2025-03-26') {
            $this->_send_json(400, $this->_error(null, self::INVALID_REQUEST, "JSON-RPC batching is not supported in protocol version {$version}"));
        }

        if (empty($messages)) {
            $this->_send_json(400, $this->_error(null, self::INVALID_REQUEST, 'Empty batch'));
        }

        $responses = [];
        foreach ($messages as $message) {
            $response = $this->_handle_message($message, $version, false);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        if (empty($responses)) {
            http_response_code(202);
            die();
        }

        $this->_send_json(200, $responses);
    }

    /**
     * Handle one JSON-RPC message. Returns the response, or null for
     * notifications and client responses.
     */
    function _handle_message(mixed $message, string $version, bool $allow_initialize): ?array {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return $this->_error(null, self::INVALID_REQUEST, 'Invalid Request');
        }

        $has_id = array_key_exists('id', $message);
        $id = $message['id'] ?? null;

        if ($has_id && !is_string($id) && !is_int($id)) {
            return $this->_error(null, self::INVALID_REQUEST, 'Invalid Request: id must be a string or integer');
        }

        if (!isset($message['method'])) {
            // A response to a server request (we never send any): accept and ignore
            if ($has_id && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
                return null;
            }
            return $this->_error(null, self::INVALID_REQUEST, 'Invalid Request');
        }

        $method = $message['method'];
        if (!is_string($method)) {
            return $this->_error(null, self::INVALID_REQUEST, 'Invalid Request: method must be a string');
        }

        $params = $message['params'] ?? [];
        if (!is_array($params)) {
            return $has_id ? $this->_error($id, self::INVALID_PARAMS, 'params must be an object') : null;
        }

        if (!$has_id) {
            // Notifications (notifications/initialized, notifications/cancelled, ...) need no reply
            return null;
        }

        try {
            return match ($method) {
                'initialize' => $allow_initialize
                    ? $this->_result($id, $this->_initialize($params))
                    : $this->_error($id, self::INVALID_REQUEST, 'initialize must not be part of a batch'),
                'ping' => $this->_result($id, new stdClass()),
                'tools/list' => $this->_result($id, ['tools' => $this->_tool_definitions()]),
                'tools/call' => $this->_tools_call($id, $params, $version),
                default => $this->_error($id, self::METHOD_NOT_FOUND, "Method not found: {$method}"),
            };
        } catch (Throwable $e) {
            error_log('MCP ' . $method . ' failed: ' . $e->getMessage());
            return $this->_error($id, self::INTERNAL_ERROR, 'Internal error');
        }
    }

    function _initialize(array $params): array {
        $requested = $params['protocolVersion'] ?? null;
        $version = in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];

        header('Mcp-Session-Id: ' . bin2hex(random_bytes(16)));

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => 'trongate-cloud',
                'title' => 'Trongate Cloud',
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => $this->_instructions(),
        ];
    }

    function _tools_call(string|int $id, array $params, string $version): array {
        $name = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];

        if (!is_string($name) || !$this->_tool_exists($name)) {
            return $this->_error($id, self::INVALID_PARAMS, 'Unknown tool: ' . (is_string($name) ? $name : '(none)'));
        }

        if (!is_array($args)) {
            return $this->_error($id, self::INVALID_PARAMS, 'arguments must be an object');
        }

        try {
            $data = $this->_call_tool($name, $args);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->_result($id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ]);
        }

        $result = [
            'content' => [['type' => 'text', 'text' => $this->_encode($data, JSON_PRETTY_PRINT)]],
            'isError' => false,
        ];

        // Structured tool output arrived in 2025-06-18
        if ($version !== '2025-03-26') {
            $result['structuredContent'] = $data;
        }

        return $this->_result($id, $result);
    }

    /**
     * Protocol version from the MCP-Protocol-Version header: the fallback when
     * absent, false when unsupported.
     */
    function _header_protocol_version(): string|false {
        $header = trim((string) ($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''));

        if ($header === '') {
            return self::FALLBACK_PROTOCOL_VERSION;
        }

        return in_array($header, self::PROTOCOL_VERSIONS, true) ? $header : false;
    }

    /**
     * DNS rebinding protection: a browser Origin must match BASE_URL or be
     * listed in the MCP_ALLOWED_ORIGINS env var (comma separated). Requests
     * without an Origin (CLI clients such as Claude Code) are allowed.
     */
    function _origin_allowed(): bool {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        if ($origin === null || $origin === '') {
            return true;
        }

        $allowed = [$this->_origin_of(BASE_URL)];
        foreach (explode(',', (string) getenv('MCP_ALLOWED_ORIGINS')) as $extra) {
            if (trim($extra) !== '') {
                $allowed[] = $this->_origin_of(trim($extra));
            }
        }

        $origin = $this->_origin_of($origin);

        return $origin !== '' && in_array($origin, $allowed, true);
    }

    /**
     * Normalised "scheme://host[:port]" (default ports dropped), or ''.
     */
    function _origin_of(string $url): string {
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default_port = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . strtolower($parts['host']) . ($port !== null && $port !== $default_port ? ':' . $port : '');
    }

    function _instructions(): string {
        if ($this->_is_admin()) {
            return 'Admin access to Trongate Cloud across all projects: list apps and deployments, read build logs, '
                . 'deploy and roll back, and audit and retry cleanups. Deploying and rolling back change production; confirm with the user first.';
        }

        return 'Trongate Cloud hosts Trongate PHP apps. You act for the signed-in customer in the projects they are a member of: '
            . 'a project groups apps; an app is a GitHub repository deployed to {slug}.' . PLATFORM_APPS_DOMAIN . ' (and an optional custom domain), '
            . 'with its own MariaDB database. Start with list_projects or list_apps. '
            . 'Deploys, rollbacks, settings, environment variables and database changes affect live apps: say what you will change and confirm with the user first. '
            . 'Settings and environment variables take effect on the next deploy. '
            . 'Read a database with query_database (read-only); change it with insert_row, update_row, delete_row or execute_sql, which are logged on the app\'s Database tab. '
            . 'Deleting apps or projects and managing members is only possible in the control panel.';
    }

    /**
     * Who the token belongs to: the admin (MCP_SECRET) or a customer through
     * an OAuth grant (sets $customer). False when it is neither.
     */
    function _authenticate(string $token): bool {
        if ($token === '') {
            return false;
        }

        if ($this->_authorized($token)) {
            return true;
        }

        $grant = $this->model->access_grant($token);
        $customer = $grant === false ? false : $this->model->find_customer((int) $grant->customer_id);

        if ($grant === false || $customer === false) {
            return false;
        }

        $this->customer = $customer;

        return true;
    }

    function _is_admin(): bool {
        return $this->customer === null;
    }

    /**
     * The bearer token must equal MCP_SECRET. The endpoint is disabled while
     * MCP_SECRET is unset or shorter than MIN_SECRET_LENGTH.
     */
    function _authorized(string $token): bool {
        $secret = (string) getenv('MCP_SECRET');

        if (strlen($secret) < self::MIN_SECRET_LENGTH || $token === '') {
            return false;
        }

        // Hash both sides so the comparison doesn't leak the secret's length
        return hash_equals(hash('sha256', $secret), hash('sha256', $token));
    }

    function _bearer_token(): string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $header = $value;
                    break;
                }
            }
        }

        return preg_match('/^Bearer\s+(\S+)\s*$/i', (string) $header, $matches) ? $matches[1] : '';
    }

    function _result(string|int $id, array|object $result): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    function _error(string|int|null $id, int $code, string $message): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    function _encode(mixed $data, int $flags = 0): string {
        return (string) json_encode($data, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Send a JSON response and stop.
     */
    function _send_json(int $status, array $payload): never {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo $this->_encode($payload);
        die();
    }
}
