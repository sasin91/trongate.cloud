<?php

/**
 * Signed links to objects in an S3-compatible bucket (Hetzner Object Storage):
 * AWS Signature Version 4 in the query string, so whoever holds a link can
 * GET, PUT, HEAD or DELETE that one object until it expires, without the
 * bucket's keys. The worker hands a backup Job links to upload to, and the
 * panel sends a person to a link to download.
 *
 * Virtual-hosted style: https://{bucket}.{endpoint host}/{key}.
 *
 * Framework-free, like the other classes in modules/platform/live.
 */
final class Object_storage {

    /** The longest a SigV4 link may be valid */
    const MAX_EXPIRES_SECONDS = 7 * 24 * 3600;

    /** A single upload (S3 PUT) can be at most this big */
    const MAX_UPLOAD_BYTES = 5 * 1024 * 1024 * 1024;

    /**
     * @param string $endpoint e.g. https://fsn1.your-objectstorage.com
     * @param string $region   e.g. fsn1
     */
    function __construct(
        private string $endpoint,
        private string $region,
        private string $bucket,
        private string $access_key,
        private string $secret_key,
    ) {
    }

    /**
     * The bucket of deleted apps' backups (APP_BACKUPS_S3_*), or null when
     * they are not configured
     */
    static function for_app_backups(): ?self {
        $settings = [
            APP_BACKUPS_S3_ENDPOINT,
            APP_BACKUPS_S3_REGION,
            APP_BACKUPS_S3_BUCKET,
            APP_BACKUPS_S3_ACCESS_KEY,
            APP_BACKUPS_S3_SECRET_KEY,
        ];

        foreach ($settings as $setting) {
            if ($setting === '') {
                return null;
            }
        }

        return new self(...$settings);
    }

    /**
     * A link that lets its holder send $method (GET, PUT, HEAD, DELETE) for
     * $key until $expires_seconds from $now
     *
     * @param array<string, string> $query extra query parameters to sign,
     *        such as response-content-disposition for a download's file name
     */
    function link(string $method, string $key, int $expires_seconds, array $query = [], ?int $now = null): string {
        if ($expires_seconds < 1 || $expires_seconds > self::MAX_EXPIRES_SECONDS) {
            throw new InvalidArgumentException('A signed link must expire within 1 second and 7 days');
        }

        $now = $now ?? time();
        $date = gmdate('Ymd', $now);
        $timestamp = gmdate('Ymd\THis\Z', $now);
        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $host = $this->host();
        $path = $this->path($key);

        $query += [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$this->access_key}/{$scope}",
            'X-Amz-Date' => $timestamp,
            'X-Amz-Expires' => (string) $expires_seconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonical_query = self::canonical_query($query);

        $canonical_request = implode("\n", [
            $method,
            $path,
            $canonical_query,
            "host:{$host}\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $scope,
            hash('sha256', $canonical_request),
        ]);

        $signature = hash_hmac('sha256', $string_to_sign, $this->signing_key($date));

        return "https://{$host}{$path}?{$canonical_query}&X-Amz-Signature={$signature}";
    }

    /**
     * PUT the file at $path to a signed upload link; returns its size
     *
     * @throws RuntimeException when it is too big or the bucket refuses it
     */
    static function upload(string $path, string $link): int {
        $bytes = (int) filesize($path);

        if ($bytes > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException(basename($path) . " is {$bytes} bytes; one upload can be at most 5 GB");
        }

        $file = fopen($path, 'rb');
        $curl = curl_init($link);

        curl_setopt_array($curl, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $file,
            CURLOPT_INFILESIZE => $bytes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($file);

        if ($status < 200 || $status > 299) {
            throw new RuntimeException('Uploading ' . basename($path) . " failed (HTTP {$status}) {$error} " . substr((string) $response, 0, 500));
        }

        return $bytes;
    }

    /** {bucket}.{endpoint host} */
    function host(): string {
        $endpoint_host = (string) parse_url($this->endpoint, PHP_URL_HOST);

        if ($endpoint_host === '') {
            throw new InvalidArgumentException('The object storage endpoint must be a URL such as https://fsn1.your-objectstorage.com');
        }

        return "{$this->bucket}.{$endpoint_host}";
    }

    /** The key as a URI path, each segment encoded and the slashes kept */
    function path(string $key): string {
        $segments = explode('/', ltrim($key, '/'));
        $encoded = array_map('rawurlencode', $segments);

        return '/' . implode('/', $encoded);
    }

    /**
     * Query parameters sorted by name and encoded the way SigV4 signs them
     *
     * @param array<string, string> $query
     */
    static function canonical_query(array $query): string {
        ksort($query, SORT_STRING);
        $pairs = [];

        foreach ($query as $name => $value) {
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }

    function signing_key(string $date): string {
        $date_key = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
        $region_key = hash_hmac('sha256', $this->region, $date_key, true);
        $service_key = hash_hmac('sha256', 's3', $region_key, true);

        return hash_hmac('sha256', 'aws4_request', $service_key, true);
    }
}
