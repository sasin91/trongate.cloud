<?php

/**
 * Tests for App_traffic: picking one app's or one project's requests out of
 * Traefik's access log (as the pod log API returns it) and the polling cursor.
 *
 * Run: php tests/App_traffic_test.php (exits non-zero on a failure)
 */

define('APPPATH', dirname(__DIR__) . '/');

require_once APPPATH . 'modules/services/clients/Kubernetes.php';
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

/** An access log line as Traefik v3.7 writes it (headers dropped), with the API's timestamp prefix */
$line = function (string $at, string $route, int $status, string $path = '/', string $gateway = 'traefik-platform') {
    return $at . ' ' . json_encode([
        'ClientHost' => '10.0.0.5', 'DownstreamContentSize' => 335, 'DownstreamStatus' => $status, 'Duration' => 1745724,
        'RequestHost' => 'shop.trongate.dev', 'RequestMethod' => 'GET', 'RequestPath' => $path, 'RequestProtocol' => 'HTTP/1.1',
        'RouterName' => "httproute-{$route}-gw-{$gateway}-ep-websecure-0-1a2b3c4d5e@kubernetesgateway",
        'StartUTC' => substr($at, 0, 19) . '.958200276Z', 'entryPointName' => 'websecure', 'level' => 'info', 'msg' => '', 'time' => substr($at, 0, 19) . 'Z',
    ]);
};

$log = implode("\n", [
    '2026-10-09T08:00:00.100000000Z 2026-10-09T08:00:00Z INF Starting provider',
    $line('2026-10-09T08:00:01.000000001Z', 'project-3-p7-app', 200, '/shop?page=2'),
    $line('2026-10-09T08:00:02.000000000Z', 'project-3-p71-app', 502),
    $line('2026-10-09T08:00:03.000000000Z', 'project-3-p7-app', 404, '/missing', 'project-3-p7-d-example-com'),
    $line('2026-10-09T08:00:04.000000000Z', 'project-4-p9-app', 200),
    '2026-10-09T08:00:05.000000000Z {"level":"error","msg":"not an access log line"}',
    '',
]);

$test('Patterns match an app\'s routers on every gateway, never a longer id or another namespace', function () use ($same) {
    $app = App_traffic::app_pattern(['id' => 7, 'namespace' => 'project-3']);
    $same(1, preg_match($app, 'httproute-project-3-p7-app-gw-traefik-platform-ep-websecure-0-1a@kubernetesgateway'));
    $same(1, preg_match($app, 'httproute-project-3-p7-app-gw-project-3-p7-d-example-com-ep-websecure-0-1a@kubernetesgateway'));
    $same(0, preg_match($app, 'httproute-project-3-p71-app-gw-traefik-platform-ep-websecure-0-1a@kubernetesgateway'));
    $same(0, preg_match($app, 'httproute-project-31-p7-app-gw-traefik-platform-ep-websecure-0-1a@kubernetesgateway'));

    preg_match(App_traffic::project_pattern(['namespace' => 'project-3']), 'httproute-project-3-p71-app-gw-x', $match);
    $same('71', $match[1] ?? null);
});

$test('Patterns refuse a row without a tenant namespace', function () {
    try {
        App_traffic::app_pattern(['id' => 7, 'namespace' => 'traefik']);
    } catch (InvalidArgumentException $e) {
        return;
    }

    throw new RuntimeException('Expected InvalidArgumentException');
});

$test('Parsing keeps only the app\'s requests but every line\'s timestamp', function () use ($same, $log) {
    $lines = App_traffic::parse($log, App_traffic::app_pattern(['id' => 7, 'namespace' => 'project-3']));

    $same(6, count($lines));
    $same(['/shop?page=2', '/missing'], array_column(array_filter(array_column($lines, 1)), 'path'));
    $same([
        'time' => '2026-10-09T08:00:01.958200276Z', 'app_id' => 0, 'method' => 'GET', 'host' => 'shop.trongate.dev',
        'path' => '/shop?page=2', 'status' => 200, 'ms' => 1.7, 'bytes' => 335,
    ], $lines[1][1]);
});

/** Answers the pod list and each pod's log; records the log options asked for */
class Stub_kube extends Kubernetes {
    public array $asked = [];

    function __construct(private array $logs) {
        parent::__construct('https://stub', 'token');
    }

    function with_timeout(int $seconds): self {
        return $this;
    }

    function list(string $api_version, string $kind, ?string $namespace, string $label_selector = ''): array {
        return array_map(fn(string $name) => ['metadata' => ['name' => $name], 'status' => ['phase' => 'Running']], array_keys($this->logs));
    }

    function pod_log(string $namespace, string $name, int $tail_lines = 100, array $options = []): string {
        $this->asked[] = [$namespace, $name, $options];

        return $this->logs[$name];
    }
}

$test('A project read merges both Traefik pods, names each app and moves the cursor past every line', function () use ($same, $log, $line) {
    $kube = new Stub_kube(['traefik-a' => $log, 'traefik-b' => $line('2026-10-09T08:00:02.500000000Z', 'project-3-p7-app', 201)]);
    $read = App_traffic::read($kube, App_traffic::project_pattern(['namespace' => 'project-3']));

    $same([[7, 200], [71, 502], [7, 201], [7, 404]], array_map(fn(array $r) => [$r['app_id'], $r['status']], $read['requests']));
    $same('2026-10-09T08:00:05.000000000Z', $read['cursor']);
    $same(['traefik', 'traefik-a', ['container' => 'traefik', 'timestamps' => true, 'limitBytes' => App_traffic::LIMIT_BYTES, 'sinceSeconds' => App_traffic::FIRST_READ_SECONDS]], $kube->asked[0]);
});

$test('A later read asks from the cursor\'s second and drops what it already sent', function () use ($same, $log) {
    $kube = new Stub_kube(['traefik-a' => $log]);
    $read = App_traffic::read($kube, App_traffic::app_pattern(['id' => 7, 'namespace' => 'project-3']), '2026-10-09T08:00:01.000000001Z');

    $same('2026-10-09T08:00:01Z', $kube->asked[0][2]['sinceTime']);
    $same([404], array_column($read['requests'], 'status'));

    $same('', App_traffic::read(new Stub_kube([]), App_traffic::app_pattern(['id' => 7, 'namespace' => 'project-3']), 'not a cursor')['cursor']);
});

exit($failures === 0 ? 0 : 1);
