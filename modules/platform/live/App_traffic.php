<?php

require_once __DIR__ . '/App_logs.php';
require_once APPPATH . 'modules/platform/manifests/Manifests.php';

/**
 * Live requests to customer apps, read from Traefik's access log through the
 * Kubernetes pod log API. Nothing is stored: the Traffic pages poll this and
 * keep what they received in the browser (stats and CSV export are client-side).
 *
 * Traefik writes one JSON line per request (accessLog.format json, headers
 * dropped; infra repo, platform/controllers/releases/ingress.yaml) and names
 * the router of a Gateway API route
 * "httproute-{namespace}-{route}-gw-{gateway namespace}-{gateway}-ep-{entry point}-{rule}-{hash}".
 * An app's HTTPRoute is Manifests::app_name() ("p{id}-app"), so every router
 * of an app (one per Gateway: the platform's plus each custom domain's)
 * starts with "httproute-{namespace}-p{id}-app-gw-". Only matching lines
 * leave this class, so one customer never sees another's requests.
 *
 * Requests that reach an asleep app show up too: Sablier answers them with its
 * waiting page and wakes the app. Reading the log never wakes an app.
 *
 * Framework-free, like App_logs.
 */
class App_traffic {

    const NAMESPACE = 'traefik';
    const SELECTOR = 'app.kubernetes.io/name=traefik';
    const CONTAINER = 'traefik';

    /** Seconds of history on the first read */
    const FIRST_READ_SECONDS = 300;

    /** Lines read per Traefik pod and poll (every app's requests, not only this one's) */
    const TAIL_LINES = 5000;

    /** Caps one pod's log response */
    const LIMIT_BYTES = 2 * 1024 * 1024;

    /** A longer path is cut */
    const MAX_PATH_LENGTH = 500;

    /**
     * Router pattern for one app
     */
    static function app_pattern(object|array $app): string {
        $app = (array) $app;

        return '/^httproute-' . Manifests::namespace_name($app) . '-' . Manifests::app_name((int) $app['id']) . '-gw-/';
    }

    /**
     * Router pattern for every app of a project, capturing the app id
     * (Manifests::app_name() is "p{id}-app")
     */
    static function project_pattern(object|array $project): string {
        return '/^httproute-' . Manifests::namespace_name($project) . '-p([1-9][0-9]{0,9})-app-gw-/';
    }

    /**
     * Requests whose router matches $pattern, newer than $cursor (the last
     * FIRST_READ_SECONDS when empty), oldest first, from every Traefik pod.
     * The cursor is the newest log timestamp read (App_logs::cursor_key).
     *
     * @return array{requests: list<array{time: string, app_id: int, method: string, host: string, path: string, status: int, ms: float, bytes: int}>, cursor: string}
     */
    static function read(Kubernetes $kube, string $pattern, string $cursor = ''): array {
        $kube = $kube->with_timeout(App_state::TIMEOUT_SECONDS);
        $cursor = App_logs::_valid_cursor($cursor);
        $requests = [];
        $newest = $cursor;

        foreach ($kube->list('v1', 'Pod', self::NAMESPACE, self::SELECTOR) as $pod) {
            if (($pod['status']['phase'] ?? '') !== 'Running') {
                continue;
            }

            $options = ['container' => self::CONTAINER, 'timestamps' => true, 'limitBytes' => self::LIMIT_BYTES];

            // sinceTime has whole seconds; lines of the cursor's own second are filtered below
            if ($cursor === '') {
                $options['sinceSeconds'] = self::FIRST_READ_SECONDS;
            } else {
                $options['sinceTime'] = substr($cursor, 0, 19) . 'Z';
            }

            try {
                $log = $kube->pod_log(self::NAMESPACE, (string) $pod['metadata']['name'], self::TAIL_LINES, $options);
            } catch (Client_Error $e) {
                continue; // the pod went away; the next read catches up
            }

            foreach (self::parse($log, $pattern) as [$time, $request]) {
                if ($time <= $cursor) {
                    continue;
                }

                if ($request !== null) {
                    $requests[] = $request;
                }

                // Past every line read, matching or not, so the next poll skips them
                $newest = max($newest, $time);
            }
        }

        usort($requests, fn(array $a, array $b) => strcmp($a['time'], $b['time']));

        return ['requests' => $requests, 'cursor' => $newest];
    }

    /**
     * Log lines (with the API's timestamp prefix) as [log timestamp, request].
     * The request is null unless the line is an access log entry whose router
     * matches $pattern (Traefik's own messages, other apps' requests). The app
     * id is the pattern's first capture, or 0 without one.
     *
     * @return list<array{0: string, 1: array{time: string, app_id: int, method: string, host: string, path: string, status: int, ms: float, bytes: int}|null}>
     */
    static function parse(string $log, string $pattern): array {
        $lines = [];

        foreach (preg_split('/\r?\n/', $log) ?: [] as $raw) {
            if (!preg_match('/^(\S+) (.*)$/s', $raw, $match)) {
                continue;
            }

            $time = App_logs::cursor_key($match[1]);

            if ($time === '') {
                continue;
            }

            $entry = str_starts_with($match[2], '{') ? json_decode($match[2], true) : null;

            if (!is_array($entry) || !preg_match($pattern, (string) ($entry['RouterName'] ?? ''), $router)) {
                $lines[] = [$time, null];
                continue;
            }

            $path = (string) ($entry['RequestPath'] ?? '');

            $lines[] = [$time, [
                'time' => (string) ($entry['StartUTC'] ?? $time),
                'app_id' => (int) ($router[1] ?? 0),
                'method' => substr((string) ($entry['RequestMethod'] ?? ''), 0, 16),
                'host' => substr((string) ($entry['RequestHost'] ?? ''), 0, 255),
                'path' => mb_strlen($path) > self::MAX_PATH_LENGTH ? mb_substr($path, 0, self::MAX_PATH_LENGTH) . '…' : $path,
                'status' => (int) ($entry['DownstreamStatus'] ?? 0),
                'ms' => round((float) ($entry['Duration'] ?? 0) / 1e6, 1),
                'bytes' => (int) ($entry['DownstreamContentSize'] ?? 0),
            ]];
        }

        return $lines;
    }
}
