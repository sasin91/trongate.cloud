<?php

/**
 * Tests for Cluster_overview (the tg-admin overview's nodes, apps and services,
 * read live from the API server and metrics-server).
 *
 * Run: php tests/Cluster_overview_test.php (exits non-zero on a failure)
 */

define('APPPATH', dirname(__DIR__) . '/');

require_once APPPATH . 'modules/services/clients/Kubernetes.php';
require_once APPPATH . 'modules/platform/live/Cluster_overview.php';

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

/** A cluster that answers list() from fixed objects by kind; metrics can be switched off */
class Fake_kubernetes extends Kubernetes {

    /** @var list<string> */
    public array $paths = [];

    /** @param array<string, list<array>> $objects by kind */
    function __construct(private array $objects, private bool $metrics = true) {
        parent::__construct('https://kube.invalid', 'token');
    }

    function with_timeout(int $seconds): self {
        return $this;
    }

    function list(string $api_version, string $kind, ?string $namespace, string $label_selector = ''): array {
        $this->paths[] = $this->path($api_version, $kind, $namespace);

        if (!$this->metrics && str_starts_with($api_version, 'metrics.k8s.io')) {
            throw new Client_Error('the server could not find the requested resource', 404);
        }

        return $this->objects[$kind] ?? [];
    }
}

$fixture = require __DIR__ . '/fixtures/cluster_overview.php';

$test('quantities convert to millicores and bytes', function () use ($same) {
    $same(2000, Cluster_overview::cpu_millis('2'));
    $same(250, Cluster_overview::cpu_millis('250m'));
    $same(412, Cluster_overview::cpu_millis('412345678n'));
    $same(0, Cluster_overview::cpu_millis('nonsense'));
    $same(512 * 1024 * 1024, Cluster_overview::bytes('512Mi'));
    $same(8 * 1024 ** 3, Cluster_overview::bytes('8Gi'));
    $same(1000000000, Cluster_overview::bytes('1G'));
    $same(129000000, Cluster_overview::bytes('129e6'));
    $same(1234, Cluster_overview::bytes('1234'));
});

$test('reads nodes, metrics and pods cluster-wide', function () use ($same, $fixture) {
    $kube = new Fake_kubernetes($fixture);
    Cluster_overview::read($kube);
    $same([
        '/api/v1/nodes',
        '/api/v1/pods',
        '/apis/apps/v1/deployments',
        '/apis/metrics.k8s.io/v1beta1/nodes',
        '/apis/metrics.k8s.io/v1beta1/pods',
    ], $kube->paths);
});

$test('nodes add up what their running pods request, sorted by name', function () use ($same, $fixture) {
    $nodes = Cluster_overview::read(new Fake_kubernetes($fixture))['nodes'];

    $same(['node-a', 'node-b'], array_column($nodes, 'name'));
    $same(['allocatable' => 3800, 'requested' => 75, 'used' => 412], $nodes[0]['cpu']);
    $same(['allocatable' => 8 * 1024 ** 3, 'requested' => 192 * 1024 ** 2, 'used' => 2 * 1024 ** 3], $nodes[0]['memory']);
    $same(['running' => 2, 'capacity' => 110], $nodes[0]['pods']);
    // The finished build no longer holds its 4Gi
    $same(3 * 1024 ** 3, $nodes[1]['memory']['requested']);
    $same([], $nodes[0]['pressure']);
    $same(['MemoryPressure'], $nodes[1]['pressure']);
    $same('cx33', $nodes[0]['type']);
});

$test('pods the scheduler cannot place are listed with the reason', function () use ($same, $fixture) {
    $same([['namespace' => 'project-2', 'name' => 'big-xyz', 'reason' => '0/2 nodes are available: 2 Insufficient memory.']], Cluster_overview::read(new Fake_kubernetes($fixture))['pending']);
});

$test('an app counts its processes in usage but takes its state from its own Deployment', function () use ($same, $fixture) {
    $apps = Cluster_overview::read(new Fake_kubernetes($fixture))['apps'];

    $same([1, 2], array_keys($apps));
    $same('running', $apps[1]['state']['state']);
    $same(62 * 1024 ** 2, $apps[1]['memory']);
    $same(3, $apps[1]['cpu']);
    $same(2, $apps[1]['pods']);
    $same('asleep', $apps[2]['state']['state']);
    $same(0, $apps[2]['pods']);
});

$test('services get their state and usage from their own label', function () use ($same, $fixture) {
    $services = Cluster_overview::read(new Fake_kubernetes($fixture))['services'];

    $same([3], array_keys($services));
    $same('running', $services[3]['state']['state']);
    $same(2 * 1024 ** 3, $services[3]['memory']);
});

$test('without metrics-server usage is unknown but requests and states remain', function () use ($same, $fixture) {
    $overview = Cluster_overview::read(new Fake_kubernetes($fixture, false));

    $same(false, $overview['metrics']);
    $same(null, $overview['nodes'][0]['memory']['used']);
    $same(192 * 1024 ** 2, $overview['nodes'][0]['memory']['requested']);
    $same(null, $overview['apps'][1]['memory']);
    $same('running', $overview['apps'][1]['state']['state']);
});

exit($failures > 0 ? 1 : 0);
