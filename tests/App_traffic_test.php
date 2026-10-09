<?php

/**
 * Tests for App_traffic: router names per app and project, and how
 * VictoriaMetrics answers become hourly bars and totals (with a stub client).
 *
 * Run: php tests/App_traffic_test.php (exits non-zero on a failure)
 */

define('APPPATH', dirname(__DIR__) . '/');
define('VICTORIA_METRICS_URL', '');

require_once APPPATH . 'modules/platform/live/App_traffic.php';

$failures = 0;

$test = function (string $name, callable $body) use (&$failures): void {
    try {
        $body();
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}\n     {$e->getMessage()}\n";
    }
};

$same = function ($expected, $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Expected:\n" . var_export($expected, true) . "\nGot:\n" . var_export($actual, true));
    }
};

/** Answers queries from a list of [needle in the query, result] pairs and records them */
class Stub_metrics extends Victoria_metrics {
    public array $queries = [];

    function __construct(private array $answers) {
        parent::__construct('http://stub');
    }

    function query(string $promql, int $time): array {
        return $this->_answer('query', $promql);
    }

    function query_range(string $promql, int $start, int $end, int $step): array {
        $this->queries[] = ['range', $start, $end, $step];

        return $this->_answer('range', $promql);
    }

    function _answer(string $kind, string $promql): array {
        $this->queries[] = [$kind, $promql];

        foreach ($this->answers as [$answer_kind, $needle, $result]) {
            if ($answer_kind === $kind && str_contains($promql, $needle)) {
                return $result;
            }
        }

        return [];
    }
}

$now = 1_791_540_000 + 1800; // 30 minutes into an hour
$current_hour = $now - 1800;
$first_hour = $current_hour - 23 * 3600;

$test('Router names match an app\'s routes on every gateway, not a longer id', function () use ($same) {
    $routers = App_traffic::app_routers(['id' => 7, 'namespace' => 'project-3']);
    $same('httproute-project-3-p7-app-gw-.+', $routers);

    $match = fn(string $router) => preg_match('/^' . $routers . '$/', $router) === 1;
    $same(true, $match('httproute-project-3-p7-app-gw-traefik-platform-ep-websecure-0-1a2b3c4d5e@kubernetesgateway'));
    $same(true, $match('httproute-project-3-p7-app-gw-project-3-p7-d-example-com-ep-websecure-0-99aa88bb77@kubernetesgateway'));
    $same(false, $match('httproute-project-3-p71-app-gw-traefik-platform-ep-websecure-0-0f0f0f0f0f@kubernetesgateway'));

    preg_match('/^' . App_traffic::project_routers(['namespace' => 'project-3']) . '$/', 'httproute-project-3-p71-app-gw-traefik-platform-ep-websecure-0-0f@kubernetesgateway', $groups);
    $same('71', $groups[1] ?? null);
});

$test('Router names refuse a row without a tenant namespace', function () {
    try {
        App_traffic::app_routers(['id' => 7, 'namespace' => 'kube-system"}']);
    } catch (InvalidArgumentException $e) {
        return;
    }

    throw new RuntimeException('Expected InvalidArgumentException');
});

$test('Hourly bars put finished hours at their end and the current hour last', function () use ($same, $now, $current_hour, $first_hour) {
    $vm = new Stub_metrics([
        ['range', 'traefik_router_requests_total', [
            ['metric' => ['class' => '2xx'], 'values' => [[$first_hour + 3600, '10'], [$current_hour, '4']]],
            ['metric' => ['class' => '3xx'], 'values' => [[$first_hour + 3600, '1']]],
            ['metric' => ['class' => '4xx'], 'values' => [[$current_hour, '2.6']]],
        ]],
        ['query', '[1800s]', [
            ['metric' => ['class' => '5xx'], 'value' => [$now, '3']],
        ]],
        ['query', 'histogram_quantiles', [
            ['metric' => ['phi' => '0.5'], 'value' => [$now, '0.042']],
            ['metric' => ['phi' => '0.95'], 'value' => [$now, '+Inf']],
        ]],
        ['query', 'traefik_router_responses_bytes_total', [['metric' => [], 'value' => [$now, '12345.6']]]],
    ]);

    $report = App_traffic::app($vm, ['id' => 7, 'namespace' => 'project-3'], $now);

    $same(['range', $first_hour + 3600, $current_hour, 3600], $vm->queries[0]);
    $same(24, count($report['hours']));
    $same(['start' => $first_hour, 'ok' => 11, 'client_errors' => 0, 'server_errors' => 0], $report['hours'][0]);
    $same(['start' => $current_hour - 3600, 'ok' => 4, 'client_errors' => 3, 'server_errors' => 0], $report['hours'][22]);
    $same(['start' => $current_hour, 'ok' => 0, 'client_errors' => 0, 'server_errors' => 3], $report['hours'][23]);
    $same([21, 3, 3], [$report['requests'], $report['client_errors'], $report['server_errors']]);
    $same(0.042, $report['median']);
    $same(INF, $report['p95']);
    $same(12346, $report['bytes_out']);
    $same(false, array_key_exists('apps', $report));
});

$test('No requests skips the latency and size queries', function () use ($same, $now) {
    $vm = new Stub_metrics([]);
    $report = App_traffic::project($vm, ['namespace' => 'project-3'], $now);

    $same(2, count(array_filter($vm->queries, fn(array $q) => $q[0] !== 'range' || is_string($q[1]))));
    $same([0, null, []], [$report['requests'], $report['median'], $report['apps']]);
});

$test('A project report breaks requests down per app, busiest first', function () use ($same, $now) {
    $vm = new Stub_metrics([
        ['query', 'sum by (app, class)', [
            ['metric' => ['app' => '7', 'class' => '2xx'], 'value' => [$now, '5']],
            ['metric' => ['app' => '71', 'class' => '2xx'], 'value' => [$now, '20']],
            ['metric' => ['app' => '71', 'class' => '5xx'], 'value' => [$now, '2']],
        ]],
        ['query', '[1800s]', [['metric' => ['class' => '2xx'], 'value' => [$now, '27']]]],
        ['query', 'histogram_quantiles', [
            ['metric' => ['app' => '7', 'phi' => '0.95'], 'value' => [$now, '0.3']],
            ['metric' => ['app' => '7', 'phi' => '0.5'], 'value' => [$now, 'NaN']],
        ]],
    ]);

    $report = App_traffic::project($vm, ['namespace' => 'project-3'], $now);

    $same([71, 7], array_keys($report['apps']));
    $same(['requests' => 22, 'client_errors' => 0, 'server_errors' => 2, 'median' => null, 'p95' => null], $report['apps'][71]);
    $same(['requests' => 5, 'client_errors' => 0, 'server_errors' => 0, 'median' => null, 'p95' => 0.3], $report['apps'][7]);

    $by_app = array_values(array_filter($vm->queries, fn(array $q) => is_string($q[1]) && str_contains($q[1], 'sum by (app, class)')))[0][1];
    $same(true, str_contains($by_app, 'label_replace(increase(traefik_router_requests_total{router=~"httproute-project-3-p([0-9]+)-app-gw-.+"}['));
    $same(true, str_contains($by_app, '"app", "$1", "router"'));
});

exit($failures === 0 ? 0 : 1);
