<?php

class Client_Error extends Exception {
}

/**
 * Minimal JSON HTTP client. Framework-free so the worker can load it.
 */
abstract class Client {

    protected string $api_base = '';

    abstract function headers(): array;

    /**
     * Make an API request relative to $api_base
     *
     * $headers are merged over headers(); a header given here replaces one with the same name.
     */
    function request(string $method, string $endpoint, array $data = [], array $headers = []): array {
        return $this->_request($method, $this->api_base . $endpoint, $data, $headers);
    }

    /**
     * Send a request, retrying only on connection errors (code 0), 429 and 5xx
     */
    function _request(string $method, string $url, array $data = [], array $headers = [], int $attempts = 3, int $sleep_ms = 100): array {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->send_request($method, $url, $data, $headers);
            } catch (Client_Error $e) {
                $code = $e->getCode();
                $retryable = $code === 0 || $code === 429 || $code >= 500;

                if (!$retryable || $attempt >= $attempts) {
                    throw $e;
                }

                usleep($sleep_ms * 1000 * $attempt);
            }
        }
    }

    function send_request(string $method, string $url, array $data = [], array $headers = []): array {
        $method = strtoupper($method);
        // A DELETE only carries a body when given one (e.g. Kubernetes DeleteOptions)
        $has_body = in_array($method, ['POST', 'PUT', 'PATCH'], true) || ($method === 'DELETE' && $data !== []);

        if ($has_body && $this->_find_header($headers, 'Content-Type') === null && $this->_find_header($this->headers(), 'Content-Type') === null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->_merge_headers($this->headers(), $headers));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        foreach ($this->_curl_options() as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        switch ($method) {
            case 'GET':
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                break;
        }

        if ($has_body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_SLASHES));
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            throw new Client_Error("Curl Error: {$error}", 0);
        }

        $body = json_decode((string) $response, true);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($http_code >= 400) {
            $message = $body['error']['message'] ?? $body['message'] ?? "HTTP {$http_code}";
            throw new Client_Error("Client API error ({$http_code}): {$message}", $http_code);
        }

        return is_array($body) ? $body : [];
    }

    /**
     * Extra curl options (e.g. CURLOPT_CAINFO); override in subclasses
     */
    function _curl_options(): array {
        return [];
    }

    /**
     * Merge header lines; later lines replace earlier ones with the same (case-insensitive) name
     */
    function _merge_headers(array $base, array $extra): array {
        $merged = [];

        foreach (array_merge($base, $extra) as $line) {
            $name = strtolower(trim(explode(':', $line, 2)[0]));
            $merged[$name] = $line;
        }

        return array_values($merged);
    }

    function _find_header(array $headers, string $name): ?string {
        foreach ($headers as $line) {
            $parts = explode(':', $line, 2);

            if (strcasecmp(trim($parts[0]), $name) === 0) {
                return trim($parts[1] ?? '');
            }
        }

        return null;
    }
}
