<?php

class Client_Error extends Exception {}

abstract class Client {
    abstract function headers(): array;

    function _request(string $method, string $url, array $data = [], $retry_times = 3, $retry_sleep = 100): array {
        $times = $retry_times;
        $attempts = 0;

        $backoff = [];

        if (is_array($times)) {
            $backoff = $times;

            $times = count($times) + 1;
        }

        beginning:
        $attempts++;
        $times--;

        try {
            return $this->send_request($method, $url, $data);
        } catch (Throwable $e) {
            if ($times < 1 || ($e instanceof Client_Error && $e->getCode() >= 500)) {
                throw $e;
            }

            $sleep = $backoff[$attempts - 1] ?? $retry_sleep;

            if ($sleep) {
                usleep($sleep * 1000);
            }

            goto beginning;
        }
    }

    function send_request(string $method, string $url, array $data = []): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers());
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            throw new Client_Error("Curl Error: {$error}");
        }

        $body = json_decode($response, true);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($http_code >= 400) {
            $message = $body['error']['message'] ?? "HTTP {$http_code}";
            throw new Client_Error("Client API error ({$http_code}): {$message}", $http_code);
        }

        return $body;
    }
}