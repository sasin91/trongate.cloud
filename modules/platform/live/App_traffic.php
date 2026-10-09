<?php

require_once APPPATH . 'modules/platform/manifests/Manifests.php';

/**
 * Requests to customer apps, sampled from Traefik's router counters.
 *
 * The worker's sample_traffic task reads each Traefik pod's metrics page
 * (port METRICS_PORT, inside the cluster only) about once a minute, takes the
 * change of every counter since its last read (traffic_counter) and adds it to
 * the app's current 5-minute window (app_traffic): requests by status class,
 * response time buckets and bytes sent. The app and project pages read the
 * windows back (report()). No metrics database is involved.
 *
 * Traefik names the router of a Gateway API route
 * "httproute-{namespace}-{route}-gw-{gateway namespace}-{gateway}-ep-{entry point}-{rule}-{hash}",
 * and an app's HTTPRoute is Manifests::app_name() ("p{id}-app"), so every
 * router of an app (one per Gateway: the platform's plus each custom
 * domain's) starts with "httproute-{namespace}-p{id}-app-gw-". Requests that
 * reach an asleep app count too: Sablier answers them with its waiting page
 * and wakes the app, which is what a visitor did. Reading Traefik never wakes
 * an app.
 *
 * Framework-free: the worker records, the web app reads through its models.
 */
class App_traffic {

    /** Width of one window in app_traffic.bucket */
    const BUCKET_SECONDS = 300;

    /** Windows are kept this long */
    const RETENTION_DAYS = 7;

    /** Counters of a Traefik pod not seen for this long are forgotten (the pod is gone) */
    const COUNTER_RETENTION_HOURS = 24;

    /** Hours on the cards, the current (partial) one last */
    const HOURS = 24;

    const TRAEFIK_NAMESPACE = 'traefik';
    const TRAEFIK_SELECTOR = 'app.kubernetes.io/name=traefik';
    const METRICS_PORT = 9100;
    const FETCH_TIMEOUT_SECONDS = 5;

    /**
     * Response time bucket bounds (seconds, as Traefik prints them) => app_traffic
     * column. MUST match metrics.prometheus.buckets on the Traefik release
     * (infra repo, platform/controllers/releases/ingress.yaml); other bounds are ignored.
     */
    const LE_COLUMNS = [
        '0.025' => 'le_0_025',
        '0.05' => 'le_0_05',
        '0.1' => 'le_0_1',
        '0.25' => 'le_0_25',
        '0.5' => 'le_0_5',
        '1' => 'le_1',
        '2.5' => 'le_2_5',
        '5' => 'le_5',
        '10' => 'le_10',
    ];

    const COUNT_COLUMNS = ['ok', 'client_errors', 'server_errors', 'bytes_out'];

    const METRIC_PATTERN = '/^(traefik_router_(?:requests_total|request_duration_seconds_bucket|responses_bytes_total))\{(.*)\} (\S+)/';
    const ROUTER_PATTERN = '/^httproute-(project-[1-9][0-9]{0,9})-p([1-9][0-9]{0,9})-app-gw-/';

    /** traffic_counter row that marks a pod as read before, even with no app counters yet */
    const POD_MARKER = 'pod';

    /**
     * The app routers' counters on a Traefik metrics page
     *
     * @return list<array{series: string, metric: string, namespace: string, app_id: int, labels: array<string, string>, value: float}>
     */
    static function parse(string $text): array {
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            if (!preg_match(self::METRIC_PATTERN, $line, $match)) {
                continue;
            }

            preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\\\]|\\\\.)*)"/', $match[2], $pairs, PREG_SET_ORDER);
            $labels = [];
            foreach ($pairs as [, $name, $value]) {
                $labels[$name] = stripcslashes($value);
            }

            if (!preg_match(self::ROUTER_PATTERN, $labels['router'] ?? '', $router) || !is_numeric($match[3])) {
                continue;
            }

            $lines[] = [
                'series' => sha1($match[1] . '{' . $match[2] . '}'),
                'metric' => $match[1],
                'namespace' => $router[1],
                'app_id' => (int) $router[2],
                'labels' => $labels,
                'value' => (float) $match[3],
            ];
        }

        return $lines;
    }

    /**
     * What each app gained since the previous read of one pod's counters.
     * A counter lower than before restarted (Traefik restarted in the pod),
     * so all of it is new. A counter not read before is new traffic when
     * $count_new (the pod was read before, or started since the last run),
     * otherwise only a starting point.
     *
     * @param list<array{series: string, metric: string, app_id: int, labels: array<string, string>, value: float}> $lines parse()
     * @param array<string, float> $previous series => value
     * @return array<int, array<string, int>> app id => column => amount
     */
    static function deltas(array $lines, array $previous, bool $count_new): array {
        $deltas = [];

        foreach ($lines as $line) {
            $before = $previous[$line['series']] ?? null;
            $delta = match (true) {
                $before === null => $count_new ? $line['value'] : 0.0,
                $line['value'] < $before => $line['value'],
                default => $line['value'] - $before,
            };

            $column = match ($line['metric']) {
                'traefik_router_requests_total' => match (substr($line['labels']['code'] ?? '', 0, 1)) {
                    '4' => 'client_errors',
                    '5' => 'server_errors',
                    default => 'ok',
                },
                'traefik_router_responses_bytes_total' => 'bytes_out',
                default => self::LE_COLUMNS[self::_bound($line['labels']['le'] ?? '')] ?? null,
            };

            $amount = (int) round($delta);

            if ($column === null || $amount <= 0) {
                continue;
            }

            $deltas[$line['app_id']][$column] = ($deltas[$line['app_id']][$column] ?? 0) + $amount;
        }

        return $deltas;
    }

    /**
     * Read every running Traefik pod's counters and add what changed to the
     * apps' current windows; returns a summary. $fetch(url) returns the
     * metrics page (tests pass their own).
     *
     * @param ?callable(string): string $fetch
     * @return array{pods: int, failed: int, apps: int, requests: int}
     */
    static function sample(PDO $db, Kubernetes $kube, int $now, ?callable $fetch = null): array {
        $fetch ??= self::_fetch(...);
        $summary = ['pods' => 0, 'failed' => 0, 'apps' => 0, 'requests' => 0];

        // App id => its project's namespace: a router only counts for the app it names
        $apps = [];
        foreach ($db->query('SELECT a.id, p.namespace FROM app a JOIN project p ON p.id = a.project_id WHERE a.deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $apps[(int) $row['id']] = (string) $row['namespace'];
        }

        // seen_at is written as UTC; read it back as such, whatever the session time zone
        $last_seen = $db->query('SELECT MAX(seen_at) FROM traffic_counter')->fetchColumn();
        $last_run = is_string($last_seen) ? strtotime($last_seen . ' UTC') : false;
        $bucket = gmdate('Y-m-d H:i:s', $now - $now % self::BUCKET_SECONDS);
        $seen_at = gmdate('Y-m-d H:i:s', $now);
        $touched = [];

        foreach ($kube->list('v1', 'Pod', self::TRAEFIK_NAMESPACE, self::TRAEFIK_SELECTOR) as $pod) {
            $uid = (string) ($pod['metadata']['uid'] ?? '');
            $ip = (string) ($pod['status']['podIP'] ?? '');

            if ($uid === '' || $ip === '' || ($pod['status']['phase'] ?? '') !== 'Running') {
                continue;
            }

            $summary['pods']++;

            try {
                $lines = self::parse($fetch('http://' . (str_contains($ip, ':') ? "[{$ip}]" : $ip) . ':' . self::METRICS_PORT . '/metrics'));
            } catch (Throwable $e) {
                $summary['failed']++;
                continue;
            }

            $lines = array_values(array_filter($lines, fn(array $line) => ($apps[$line['app_id']] ?? null) === $line['namespace']));

            $statement = $db->prepare('SELECT series, value FROM traffic_counter WHERE pod_uid = ?');
            $statement->execute([$uid]);
            $previous = array_map('floatval', $statement->fetchAll(PDO::FETCH_KEY_PAIR));

            $started = strtotime((string) ($pod['status']['startTime'] ?? '')) ?: $now;
            $count_new = $previous !== [] || ($last_run !== false && $started >= $last_run);

            self::_record($db, $uid, $bucket, $seen_at, $lines, self::deltas($lines, $previous, $count_new), $touched, $summary);
        }

        $summary['apps'] = count($touched);

        return $summary;
    }

    /**
     * Add one pod's deltas to the windows and remember its counters, in one transaction
     */
    static function _record(PDO $db, string $uid, string $bucket, string $seen_at, array $lines, array $deltas, array &$touched, array &$summary): void {
        $columns = array_merge(self::COUNT_COLUMNS, array_values(self::LE_COLUMNS));
        $updates = implode(', ', array_map(fn(string $column) => "{$column} = {$column} + VALUES({$column})", $columns));
        $upsert = $db->prepare(
            'INSERT INTO app_traffic (app_id, bucket, ' . implode(', ', $columns) . ') VALUES (?, ?' . str_repeat(', ?', count($columns)) . ')
             ON DUPLICATE KEY UPDATE ' . $updates
        );
        $counter = $db->prepare(
            'INSERT INTO traffic_counter (pod_uid, series, value, seen_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), seen_at = VALUES(seen_at)'
        );

        $db->beginTransaction();

        try {
            foreach ($deltas as $app_id => $amounts) {
                $upsert->execute(array_merge([$app_id, $bucket], array_map(fn(string $column) => $amounts[$column] ?? 0, $columns)));
                $touched[$app_id] = true;
                $summary['requests'] += ($amounts['ok'] ?? 0) + ($amounts['client_errors'] ?? 0) + ($amounts['server_errors'] ?? 0);
            }

            $counter->execute([$uid, sha1(self::POD_MARKER), 0, $seen_at]);
            foreach ($lines as $line) {
                $counter->execute([$uid, $line['series'], $line['value'], $seen_at]);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete windows past RETENTION_DAYS and counters of pods gone for
     * COUNTER_RETENTION_HOURS; returns how many windows
     */
    static function prune(PDO $db): int {
        $db->prepare('DELETE FROM traffic_counter WHERE seen_at < UTC_TIMESTAMP() - INTERVAL ? HOUR')->execute([self::COUNTER_RETENTION_HOURS]);

        $statement = $db->prepare('DELETE FROM app_traffic WHERE bucket < UTC_TIMESTAMP() - INTERVAL ? DAY');
        $statement->execute([self::RETENTION_DAYS]);

        return $statement->rowCount();
    }

    /**
     * Hourly requests by outcome and the numbers derived from them, for the
     * last HOURS hours of app_traffic rows (with app_id when $by_app). Times
     * are seconds; median and p95 are null without requests and INF past the
     * largest bucket (10 s).
     *
     * @param list<array<string, int|string>> $rows
     * @return array{
     *     hours: list<array{start: int, ok: int, client_errors: int, server_errors: int}>,
     *     requests: int, client_errors: int, server_errors: int,
     *     median: float|null, p95: float|null, bytes_out: int,
     *     apps?: array<int, array{requests: int, client_errors: int, server_errors: int, median: float|null, p95: float|null}>
     * }
     */
    static function report(array $rows, int $now, bool $by_app): array {
        $current_hour = $now - $now % 3600;
        $first_hour = $current_hour - (self::HOURS - 1) * 3600;

        $hours = [];
        for ($i = 0; $i < self::HOURS; $i++) {
            $hours[] = ['start' => $first_hour + $i * 3600, 'ok' => 0, 'client_errors' => 0, 'server_errors' => 0];
        }

        $totals = self::_empty_totals();
        $apps = [];

        foreach ($rows as $row) {
            $index = intdiv((int) strtotime($row['bucket'] . ' UTC') - $first_hour, 3600);

            if ($index < 0 || $index >= self::HOURS) {
                continue;
            }

            foreach (['ok', 'client_errors', 'server_errors'] as $column) {
                $hours[$index][$column] += (int) $row[$column];
            }

            self::_add_totals($totals, $row);

            if ($by_app) {
                $apps[(int) $row['app_id']] ??= self::_empty_totals();
                self::_add_totals($apps[(int) $row['app_id']], $row);
            }
        }

        $report = ['hours' => $hours] + self::_summary($totals) + ['bytes_out' => $totals['bytes_out']];

        if ($by_app) {
            $report['apps'] = array_map(self::_summary(...), $apps);
            uasort($report['apps'], fn(array $a, array $b) => $b['requests'] <=> $a['requests']);
        }

        return $report;
    }

    /**
     * The q-quantile response time from cumulative bucket counts, interpolated
     * within its bucket (as Prometheus' histogram_quantile)
     *
     * @param array<string, int> $buckets LE_COLUMNS column => requests within it
     */
    static function quantile(float $q, array $buckets, int $requests): ?float {
        // No buckets at all: Traefik's bounds differ from LE_COLUMNS
        if ($requests <= 0 || array_sum(array_intersect_key($buckets, array_flip(self::LE_COLUMNS))) === 0) {
            return null;
        }

        $rank = $q * $requests;
        $lower = 0.0;
        $below = 0;

        foreach (self::LE_COLUMNS as $bound => $column) {
            $count = min($requests, (int) ($buckets[$column] ?? 0));

            if ($count >= $rank && $count > $below) {
                return $lower + ((float) $bound - $lower) * ($rank - $below) / ($count - $below);
            }

            $lower = (float) $bound;
            $below = max($below, $count);
        }

        return INF;
    }

    static function _empty_totals(): array {
        return array_fill_keys(array_merge(self::COUNT_COLUMNS, array_values(self::LE_COLUMNS)), 0);
    }

    static function _add_totals(array &$totals, array $row): void {
        foreach ($totals as $column => $value) {
            $totals[$column] = $value + (int) ($row[$column] ?? 0);
        }
    }

    /**
     * @return array{requests: int, client_errors: int, server_errors: int, median: float|null, p95: float|null}
     */
    static function _summary(array $totals): array {
        $requests = $totals['ok'] + $totals['client_errors'] + $totals['server_errors'];

        return [
            'requests' => $requests,
            'client_errors' => $totals['client_errors'],
            'server_errors' => $totals['server_errors'],
            'median' => self::quantile(0.5, $totals, $requests),
            'p95' => self::quantile(0.95, $totals, $requests),
        ];
    }

    /**
     * A bucket bound as LE_COLUMNS keys it ("0.10" and "1.0" print as "0.1" and "1")
     */
    static function _bound(string $le): string {
        return is_numeric($le) ? (string) (float) $le : $le;
    }

    static function _fetch(string $url): string {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::FETCH_TIMEOUT_SECONDS,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if (!is_string($body) || $status !== 200) {
            throw new RuntimeException("Traefik metrics: HTTP {$status} " . curl_error($curl));
        }

        return $body;
    }
}
