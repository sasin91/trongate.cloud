<?php

/**
 * Tests for App_traffic: reading Traefik's metrics page, turning counter
 * changes into per-app amounts, and the hourly report with response times.
 *
 * Run: php tests/App_traffic_test.php (exits non-zero on a failure)
 */

define('APPPATH', dirname(__DIR__) . '/');

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

$router = fn(string $namespace, string $route, string $gateway = 'traefik-platform') => "httproute-{$namespace}-{$route}-gw-{$gateway}-ep-websecure-0-1a2b3c4d5e@kubernetesgateway";

$page = <<<TXT
# HELP traefik_router_requests_total How many HTTP requests are processed on a router, partitioned by service, status code, protocol, and method.
# TYPE traefik_router_requests_total counter
traefik_router_requests_total{code="200",method="GET",protocol="http",router="{$router('project-3', 'p7-app')}",service="x"} 10
traefik_router_requests_total{code="404",method="GET",protocol="http",router="{$router('project-3', 'p7-app', 'project-3-p7-d-example-com')}",service="x"} 2
traefik_router_requests_total{code="502",method="POST",protocol="http",router="{$router('project-3', 'p71-app')}",service="x"} 1
traefik_router_requests_total{code="200",method="GET",protocol="http",router="{$router('traefik', 'http-to-https')}",service="x"} 99
traefik_router_request_duration_seconds_bucket{code="200",method="GET",protocol="http",router="{$router('project-3', 'p7-app')}",service="x",le="0.025"} 4
traefik_router_request_duration_seconds_bucket{code="200",method="GET",protocol="http",router="{$router('project-3', 'p7-app')}",service="x",le="0.1"} 9
traefik_router_request_duration_seconds_bucket{code="200",method="GET",protocol="http",router="{$router('project-3', 'p7-app')}",service="x",le="+Inf"} 10
traefik_router_responses_bytes_total{code="200",method="GET",protocol="http",router="{$router('project-3', 'p7-app')}",service="x"} 2.5e+06
traefik_service_requests_total{code="200",method="GET",protocol="http",service="x"} 10
TXT;

$test('The metrics page yields app routers only, on every gateway, without mixing up p7 and p71', function () use ($same, $page) {
    $lines = App_traffic::parse($page);

    $same(7, count($lines));
    $same([7, 7, 71, 7, 7, 7, 7], array_column($lines, 'app_id'));
    $same('project-3', $lines[0]['namespace']);
    $same('404', $lines[1]['labels']['code']);
    $same(2500000.0, $lines[6]['value']);
    $same(7, count(array_unique(array_column($lines, 'series'))));
});

$test('Counter changes become amounts per app; restarts count in full', function () use ($same, $page) {
    $lines = App_traffic::parse($page);
    $previous = [
        $lines[0]['series'] => 4.0,   // 200s: 4 -> 10
        $lines[2]['series'] => 5.0,   // 502s: 5 -> 1, Traefik restarted
        $lines[6]['series'] => 2.0e6, // bytes
    ];

    $same([
        7 => ['ok' => 6, 'client_errors' => 2, 'le_0_025' => 4, 'le_0_1' => 9, 'bytes_out' => 500000],
        71 => ['server_errors' => 1],
    ], App_traffic::deltas($lines, $previous, true));
});

$test('Counters seen for the first time on a pod read before are only a starting point when told so', function () use ($same, $page) {
    $lines = App_traffic::parse($page);
    $previous = array_combine(array_column($lines, 'series'), array_column($lines, 'value'));

    $same([], App_traffic::deltas($lines, [], false));
    $same([], App_traffic::deltas($lines, $previous, true));
});

$test('Quantiles interpolate inside their bucket like histogram_quantile', function () use ($same) {
    $buckets = ['le_0_025' => 50, 'le_0_05' => 80, 'le_0_1' => 90, 'le_0_25' => 95, 'le_0_5' => 100, 'le_1' => 100, 'le_2_5' => 100, 'le_5' => 100, 'le_10' => 100];

    $same(0.025, App_traffic::quantile(0.5, $buckets, 100));
    $same(0.25, App_traffic::quantile(0.95, $buckets, 100));
    $same(INF, App_traffic::quantile(0.95, ['le_10' => 50], 100));
    $same(null, App_traffic::quantile(0.5, $buckets, 0));
    $same(null, App_traffic::quantile(0.5, [], 10));
});

$test('The report has 24 clock hours, the current one last, and a share per app', function () use ($same) {
    $now = 1_791_540_000 + 1800;
    $current_hour = $now - 1800;
    $row = fn(int $app_id, int $time, int $ok, int $client, int $server) => [
        'app_id' => (string) $app_id, 'bucket' => gmdate('Y-m-d H:i:s', $time),
        'ok' => (string) $ok, 'client_errors' => (string) $client, 'server_errors' => (string) $server, 'bytes_out' => '1000',
        'le_0_025' => (string) ($ok + $client), 'le_0_05' => (string) ($ok + $client), 'le_0_1' => (string) ($ok + $client),
        'le_0_25' => (string) ($ok + $client), 'le_0_5' => (string) ($ok + $client), 'le_1' => (string) ($ok + $client + $server),
        'le_2_5' => (string) ($ok + $client + $server), 'le_5' => (string) ($ok + $client + $server), 'le_10' => (string) ($ok + $client + $server),
    ];

    $rows = [
        $row(7, $current_hour - 24 * 3600, 500, 0, 0), // older than the 24 hours shown
        $row(7, $current_hour - 23 * 3600 + 600, 10, 1, 0),
        $row(71, $current_hour - 3600 + 300, 2, 0, 3),
        $row(71, $current_hour + 300, 20, 0, 0),
        $row(71, $current_hour + 900, 5, 0, 0),
    ];

    $report = App_traffic::report($rows, $now, true);

    $same(24, count($report['hours']));
    $same(['start' => $current_hour - 23 * 3600, 'ok' => 10, 'client_errors' => 1, 'server_errors' => 0], $report['hours'][0]);
    $same(['start' => $current_hour - 3600, 'ok' => 2, 'client_errors' => 0, 'server_errors' => 3], $report['hours'][22]);
    $same(['start' => $current_hour, 'ok' => 25, 'client_errors' => 0, 'server_errors' => 0], $report['hours'][23]);
    $same([41, 1, 3, 4000], [$report['requests'], $report['client_errors'], $report['server_errors'], $report['bytes_out']]);
    $same([71, 7], array_keys($report['apps']));
    $same(30, $report['apps'][71]['requests']);
    $same(0.0125, $report['apps'][7]['median']);
    $same(false, array_key_exists('apps', App_traffic::report($rows, $now, false)));
});

exit($failures === 0 ? 0 : 1);
