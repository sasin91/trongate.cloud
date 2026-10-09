<?php

require_once __DIR__ . '/Client.php';

/**
 * Read-only client for the cluster's VictoriaMetrics (vmsingle) query API.
 * Used by App_traffic for the Traffic cards; page loads wait on it, so it
 * fails fast and never retries.
 *
 * Framework-free.
 */
class Victoria_metrics extends Client {

    /** Seconds a query may take before the card gives up */
    const TIMEOUT_SECONDS = 3;

    function __construct(string $url) {
        $this->api_base = rtrim($url, '/');
    }

    /**
     * The client for VICTORIA_METRICS_URL (config/platform.php), or null when
     * it is not set (local development without a cluster)
     */
    static function from_env(): ?self {
        return VICTORIA_METRICS_URL === '' ? null : new self(VICTORIA_METRICS_URL);
    }

    function headers(): array {
        return ['Accept: application/json'];
    }

    /**
     * Instant query at $time
     *
     * @return list<array<string, mixed>> series with 'metric' (labels) and 'value' ([time, value])
     */
    function query(string $promql, int $time): array {
        return $this->_result('/api/v1/query?' . http_build_query(['query' => $promql, 'time' => $time]));
    }

    /**
     * Range query from $start to $end every $step seconds
     *
     * @return list<array<string, mixed>> series with 'metric' (labels) and 'values' ([time, value] pairs)
     */
    function query_range(string $promql, int $start, int $end, int $step): array {
        return $this->_result('/api/v1/query_range?' . http_build_query([
            'query' => $promql,
            'start' => $start,
            'end' => $end,
            'step' => $step,
        ]));
    }

    function _curl_options(): array {
        return [
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
        ];
    }

    function _result(string $endpoint): array {
        $body = $this->send_request('GET', $this->api_base . $endpoint);

        if (($body['status'] ?? null) !== 'success' || !is_array($body['data']['result'] ?? null)) {
            throw new Client_Error('VictoriaMetrics: ' . (string) ($body['error'] ?? 'unexpected response'), 0);
        }

        return $body['data']['result'];
    }
}
