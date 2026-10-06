<?php

require_once __DIR__ . '/App_state.php';

/**
 * Recent output (stdout/stderr) of a customer app's pods, read through the
 * Kubernetes pod log API. Nothing is stored: the kubelet keeps the log of the
 * current and the previous container, so an asleep app has no logs to show.
 *
 * Framework-free, like App_state. Reading never wakes an asleep app: it only
 * talks to the API server, never to the app's route.
 */
class App_logs {

    /** The app container in Manifests::app_deployment (init containers are skipped) */
    const CONTAINER = 'app';

    /** Lines read per pod on the first read */
    const TAIL_LINES = 300;

    /** Caps one pod's log response, so a chatty app cannot blow up a page */
    const LIMIT_BYTES = 256 * 1024;

    /** A longer line is cut; a stack trace dumped as one line stays readable */
    const MAX_LINE_LENGTH = 4000;

    /**
     * The app's state and its log lines newer than $cursor (all recent ones
     * when empty), oldest first.
     *
     * The cursor is the last line's timestamp as cursor_key() writes it; lines
     * of every pod of the app are merged by it, so a rollout reads as one log.
     *
     * @return array{state: array{state: string, label: string, detail: string}, lines: list<array{time: string, pod: string, text: string}>, cursor: string, pods: int}
     */
    static function read(Kubernetes $kube, string $namespace, int $app_id, string $cursor = ''): array {
        if (!Manifests::is_tenant_namespace($namespace)) {
            throw new InvalidArgumentException("Not a tenant namespace: '{$namespace}'");
        }

        $kube = $kube->with_timeout(App_state::TIMEOUT_SECONDS);
        $name = Manifests::app_name($app_id);
        $cursor = self::_valid_cursor($cursor);

        $deployment = $kube->get('apps/v1', 'Deployment', $namespace, $name);

        if ($deployment === null) {
            return ['state' => App_state::_make('not_running'), 'lines' => [], 'cursor' => $cursor, 'pods' => 0];
        }

        $pods = $kube->list('v1', 'Pod', $namespace, 'app.kubernetes.io/name=' . $name);
        $state = App_state::_state($deployment, $pods);

        $lines = [];
        $readable = 0;

        foreach ($pods as $pod) {
            $previous = self::_reads_previous($pod);

            if ($previous === null) {
                continue;
            }

            $readable++;
            $options = [
                'container' => self::CONTAINER,
                'timestamps' => true,
                'limitBytes' => self::LIMIT_BYTES,
                'previous' => $previous,
            ];

            // sinceTime has whole seconds; lines of the cursor's own second are filtered below
            if ($cursor !== '') {
                $options['sinceTime'] = substr($cursor, 0, 19) . 'Z';
            }

            try {
                $log = $kube->pod_log($namespace, (string) $pod['metadata']['name'], self::TAIL_LINES, $options);
            } catch (Client_Error $e) {
                // The pod went away or its container is between restarts; the next read catches up
                continue;
            }

            foreach (self::parse((string) $pod['metadata']['name'], $log) as $line) {
                if ($line['time'] > $cursor) {
                    $lines[] = $line;
                }
            }
        }

        usort($lines, fn(array $a, array $b) => strcmp($a['time'], $b['time']));

        // Two pods each send up to TAIL_LINES; show the newest TAIL_LINES of the merge
        $lines = array_slice($lines, -self::TAIL_LINES);

        return [
            'state' => $state,
            'lines' => $lines,
            'cursor' => $lines === [] ? $cursor : end($lines)['time'],
            'pods' => $readable,
        ];
    }

    /**
     * Which log of the pod's app container to read: its current one (false),
     * the previous one while it waits to restart after a crash (true), or
     * none yet (null: still pulling or starting).
     */
    static function _reads_previous(array $pod): ?bool {
        foreach ($pod['status']['containerStatuses'] ?? [] as $status) {
            if (($status['name'] ?? '') !== self::CONTAINER) {
                continue;
            }

            if (isset($status['state']['running']) || isset($status['state']['terminated'])) {
                return false;
            }

            // CrashLoopBackOff: the current container has not started, the last one's output says why
            return isset($status['lastState']['terminated']) ? true : null;
        }

        return null;
    }

    /**
     * Log lines from a "timestamps=true" pod log: "<RFC3339Nano> <text>" per line
     *
     * @return list<array{time: string, pod: string, text: string}>
     */
    static function parse(string $pod, string $log): array {
        $lines = [];

        foreach (preg_split('/\r?\n/', $log) ?: [] as $raw) {
            if (!preg_match('/^(\S+) ?(.*)$/s', $raw, $match)) {
                continue;
            }

            $time = self::cursor_key($match[1]);

            if ($time === '') {
                continue;
            }

            // Colour codes from the app's logger would show up as garbage
            $text = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $match[2]) ?? $match[2];

            $lines[] = [
                'time' => $time,
                'pod' => $pod,
                'text' => mb_strimwidth($text, 0, self::MAX_LINE_LENGTH, '…'),
            ];
        }

        return $lines;
    }

    /**
     * A log timestamp as a sortable string: RFC3339 in UTC with nine
     * fractional digits. The kubelet trims trailing zeros (…:30.5Z), which
     * would not compare as strings. Empty when it is not a timestamp.
     */
    static function cursor_key(string $timestamp): string {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?Z$/', $timestamp, $match)) {
            return '';
        }

        return $match[1] . '.' . str_pad($match[2] ?? '', 9, '0') . 'Z';
    }

    static function _valid_cursor(string $cursor): string {
        return self::cursor_key($cursor) === $cursor ? $cursor : '';
    }
}
