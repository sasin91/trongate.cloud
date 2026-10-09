<?php

require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once APPPATH . 'modules/services/clients/Victoria_metrics.php';

/**
 * Requests to customer apps over the last 24 hours, from Traefik's router
 * metrics in VictoriaMetrics (scraped from the ingress pods by vmagent; see
 * the infra repo's VMPodScrape traefik). Nothing is stored here: each page
 * load asks VictoriaMetrics, which keeps 15 days.
 *
 * Traefik names the router of a Gateway API route
 * "httproute-{namespace}-{route}-gw-{gateway namespace}-{gateway}-ep-{entry point}-{rule}-{hash}",
 * and an app's HTTPRoute is named Manifests::app_name(), so an app's routers
 * (one per Gateway: the platform's plus each custom domain's) all start with
 * "httproute-{namespace}-p{id}-app-gw-". Requests that reach an asleep app
 * count too: Sablier answers them with its waiting page and wakes the app,
 * which is what a visitor did. Scraping Traefik never wakes an app.
 *
 * Framework-free.
 */
class App_traffic {

    /** Hours shown, the current (partial) one last */
    const HOURS = 24;

    const REQUESTS = 'traefik_router_requests_total';
    const DURATION_BUCKETS = 'traefik_router_request_duration_seconds_bucket';
    const RESPONSE_BYTES = 'traefik_router_responses_bytes_total';

    /**
     * Router regex for one app's routes
     */
    static function app_routers(object|array $app): string {
        $app = (array) $app;

        return 'httproute-' . Manifests::namespace_name($app) . '-' . Manifests::app_name((int) $app['id']) . '-gw-.+';
    }

    /**
     * Router regex for every app in a project, capturing the app id
     * (Manifests::app_name() is "p{id}-app")
     */
    static function project_routers(object|array $project): string {
        return 'httproute-' . Manifests::namespace_name($project) . '-p([0-9]+)-app-gw-.+';
    }

    /**
     * Traffic of one app: report() without the per-app breakdown
     */
    static function app(Victoria_metrics $vm, object|array $app, int $now): array {
        return self::report($vm, self::app_routers($app), $now, false);
    }

    /**
     * Traffic of a whole project, with each app's share in 'apps'
     */
    static function project(Victoria_metrics $vm, object|array $project, int $now): array {
        return self::report($vm, self::project_routers($project), $now, true);
    }

    /**
     * Hourly requests by status class and derived numbers for the routers
     * matching $routers, over the last HOURS hours. With $by_app, $routers must
     * capture the app id and the result has 'apps' keyed by it.
     *
     * Times are seconds; median and p95 are null without requests, INF past
     * the largest bucket (10 s).
     *
     * @return array{
     *     hours: list<array{start: int, ok: int, client_errors: int, server_errors: int}>,
     *     requests: int, client_errors: int, server_errors: int,
     *     median: float|null, p95: float|null, bytes_out: int,
     *     apps?: array<int, array{requests: int, client_errors: int, server_errors: int, median: float|null, p95: float|null}>
     * }
     */
    static function report(Victoria_metrics $vm, string $routers, int $now, bool $by_app): array {
        $current_hour = $now - $now % 3600;
        $first_hour = $current_hour - (self::HOURS - 1) * 3600;
        $window = ($now - $first_hour) . 's';
        $current_window = max(1, $now - $current_hour) . 's';

        // Bar i covers [start, start + 1h); a finished hour's count is taken at its end
        $hours = [];
        for ($i = 0; $i < self::HOURS; $i++) {
            $hours[] = ['start' => $first_hour + $i * 3600, 'ok' => 0, 'client_errors' => 0, 'server_errors' => 0];
        }

        foreach ($vm->query_range(self::_by_class($routers, '1h', []), $first_hour + 3600, $current_hour, 3600) as $series) {
            foreach ($series['values'] ?? [] as [$time, $value]) {
                $index = intdiv((int) $time - $first_hour, 3600) - 1;

                if (isset($hours[$index])) {
                    self::_add($hours[$index], (string) ($series['metric']['class'] ?? ''), $value);
                }
            }
        }

        foreach ($vm->query(self::_by_class($routers, $current_window, []), $now) as $series) {
            self::_add($hours[self::HOURS - 1], (string) ($series['metric']['class'] ?? ''), $series['value'][1] ?? 0);
        }

        $report = [
            'hours' => $hours,
            'requests' => 0,
            'client_errors' => 0,
            'server_errors' => 0,
            'median' => null,
            'p95' => null,
            'bytes_out' => 0,
        ];

        foreach ($hours as $hour) {
            $report['requests'] += $hour['ok'] + $hour['client_errors'] + $hour['server_errors'];
            $report['client_errors'] += $hour['client_errors'];
            $report['server_errors'] += $hour['server_errors'];
        }

        if ($report['requests'] === 0) {
            return $by_app ? $report + ['apps' => []] : $report;
        }

        $by = $by_app ? ['app'] : [];

        foreach (self::_quantiles($vm->query(self::_quantiles_query($routers, $window, $by), $now)) as $app => $quantiles) {
            if (!$by_app) {
                $report['median'] = $quantiles['median'];
                $report['p95'] = $quantiles['p95'];
            } else {
                $report['apps'][$app] = self::_empty_app();
                $report['apps'][$app]['median'] = $quantiles['median'];
                $report['apps'][$app]['p95'] = $quantiles['p95'];
            }
        }

        $bytes = $vm->query('sum(' . self::_increase(self::RESPONSE_BYTES, $routers, $window, false) . ')', $now);
        $report['bytes_out'] = (int) round((float) ($bytes[0]['value'][1] ?? 0));

        if ($by_app) {
            $report['apps'] ??= [];

            foreach ($vm->query(self::_by_class($routers, $window, ['app']), $now) as $series) {
                $app = (int) ($series['metric']['app'] ?? 0);
                $report['apps'][$app] ??= self::_empty_app();
                $counts = ['ok' => 0, 'client_errors' => 0, 'server_errors' => 0];
                self::_add($counts, (string) ($series['metric']['class'] ?? ''), $series['value'][1] ?? 0);
                $report['apps'][$app]['requests'] += array_sum($counts);
                $report['apps'][$app]['client_errors'] += $counts['client_errors'];
                $report['apps'][$app]['server_errors'] += $counts['server_errors'];
            }

            uasort($report['apps'], fn(array $a, array $b) => $b['requests'] <=> $a['requests']);
        }

        return $report;
    }

    /**
     * increase() of a router metric over $window; with $by_app the app id
     * captured by $routers becomes the label "app"
     */
    static function _increase(string $metric, string $routers, string $window, bool $by_app): string {
        $selector = $metric . '{router=~' . self::_quote($routers) . '}';
        $increase = "increase({$selector}[{$window}])";

        return $by_app
            ? 'label_replace(' . $increase . ', "app", "$1", "router", ' . self::_quote($routers) . ')'
            : $increase;
    }

    /**
     * Requests per status class ("2xx", "4xx", ...) over $window, summed by class and $by
     *
     * @param list<string> $by
     */
    static function _by_class(string $routers, string $window, array $by): string {
        $increase = self::_increase(self::REQUESTS, $routers, $window, in_array('app', $by, true));
        $labels = implode(', ', array_merge($by, ['class']));

        return "sum by ({$labels}) (label_replace({$increase}, \"class\", \"\${1}xx\", \"code\", \"([1-5]).*\"))";
    }

    /**
     * Median and 95th percentile response time over $window (MetricsQL
     * histogram_quantiles, one series per phi)
     *
     * @param list<string> $by
     */
    static function _quantiles_query(string $routers, string $window, array $by): string {
        $increase = self::_increase(self::DURATION_BUCKETS, $routers, $window, in_array('app', $by, true));
        $labels = implode(', ', array_merge($by, ['le']));

        return "histogram_quantiles(\"phi\", 0.5, 0.95, sum by ({$labels}) ({$increase}))";
    }

    /**
     * histogram_quantiles() results keyed by app id (0 without one)
     *
     * @return array<int, array{median: float|null, p95: float|null}>
     */
    static function _quantiles(array $result): array {
        $quantiles = [];

        foreach ($result as $series) {
            $app = (int) ($series['metric']['app'] ?? 0);
            $key = ['0.5' => 'median', '0.95' => 'p95'][$series['metric']['phi'] ?? ''] ?? null;

            if ($key === null) {
                continue;
            }

            $quantiles[$app] ??= ['median' => null, 'p95' => null];
            $quantiles[$app][$key] = self::_seconds((string) ($series['value'][1] ?? 'NaN'));
        }

        return $quantiles;
    }

    /**
     * A sample value as seconds: null for NaN (no requests), INF past the largest bucket
     */
    static function _seconds(string $value): ?float {
        return match (strtolower($value)) {
            'nan' => null,
            '+inf', 'inf' => INF,
            default => is_numeric($value) ? (float) $value : null,
        };
    }

    /**
     * Add a class's count to a bucket: 1xx-3xx are ok, 4xx client errors, 5xx server errors
     */
    static function _add(array &$counts, string $class, mixed $value): void {
        $key = match ($class) {
            '4xx' => 'client_errors',
            '5xx' => 'server_errors',
            default => 'ok',
        };

        $counts[$key] += (int) round((float) $value);
    }

    static function _empty_app(): array {
        return ['requests' => 0, 'client_errors' => 0, 'server_errors' => 0, 'median' => null, 'p95' => null];
    }

    /**
     * A PromQL string literal
     */
    static function _quote(string $value): string {
        return '"' . addcslashes($value, '"\\') . '"';
    }
}
