<?php

/**
 * Tests for Cluster_overview (the tg-admin overview's nodes, apps and services,
 * read live from the API server and metrics-server).
 *
 * Run: php tests/Cluster_overview_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

define('APPPATH', dirname(__DIR__) . '/');

require_once APPPATH . 'modules/services/clients/Kubernetes.php';
require_once APPPATH . 'modules/platform/live/Cluster_overview.php';

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

test('quantities convert to millicores and bytes', function () {
    assert_same(2000, Cluster_overview::cpu_millis('2'));
    assert_same(250, Cluster_overview::cpu_millis('250m'));
    assert_same(412, Cluster_overview::cpu_millis('412345678n'));
    assert_same(0, Cluster_overview::cpu_millis('nonsense'));
    assert_same(512 * 1024 * 1024, Cluster_overview::bytes('512Mi'));
    assert_same(8 * 1024 ** 3, Cluster_overview::bytes('8Gi'));
    assert_same(1000000000, Cluster_overview::bytes('1G'));
    assert_same(129000000, Cluster_overview::bytes('129e6'));
    assert_same(1234, Cluster_overview::bytes('1234'));
});

test('reads nodes, metrics and pods cluster-wide', function () use ($fixture) {
    $kube = new Fake_kubernetes($fixture);
    Cluster_overview::read($kube);
    assert_same([
        '/api/v1/nodes',
        '/api/v1/pods',
        '/apis/apps/v1/deployments',
        '/apis/metrics.k8s.io/v1beta1/nodes',
        '/apis/metrics.k8s.io/v1beta1/pods',
    ], $kube->paths);
});

test('nodes add up what their running pods request, sorted by name', function () use ($fixture) {
    $nodes = Cluster_overview::read(new Fake_kubernetes($fixture))['nodes'];

    assert_same(['node-a', 'node-b'], array_column($nodes, 'name'));
    assert_same(['allocatable' => 3800, 'requested' => 75, 'used' => 412], $nodes[0]['cpu']);
    assert_same(['allocatable' => 8 * 1024 ** 3, 'requested' => 192 * 1024 ** 2, 'used' => 2 * 1024 ** 3], $nodes[0]['memory']);
    assert_same(['running' => 2, 'capacity' => 110], $nodes[0]['pods']);
    // The finished build no longer holds its 4Gi
    assert_same(3 * 1024 ** 3, $nodes[1]['memory']['requested']);
    assert_same([], $nodes[0]['pressure']);
    assert_same(['MemoryPressure'], $nodes[1]['pressure']);
    assert_same('cx33', $nodes[0]['type']);
});

test('pods the scheduler cannot place are listed with the reason', function () use ($fixture) {
    assert_same([['namespace' => 'project-2', 'name' => 'big-xyz', 'reason' => '0/2 nodes are available: 2 Insufficient memory.']], Cluster_overview::read(new Fake_kubernetes($fixture))['pending']);
});

test('an app counts its processes in usage but takes its state from its own Deployment', function () use ($fixture) {
    $apps = Cluster_overview::read(new Fake_kubernetes($fixture))['apps'];

    assert_same([1, 2], array_keys($apps));
    assert_same('running', $apps[1]['state']['state']);
    assert_same(62 * 1024 ** 2, $apps[1]['memory']);
    assert_same(3, $apps[1]['cpu']);
    assert_same(2, $apps[1]['pods']);
    assert_same('asleep', $apps[2]['state']['state']);
    assert_same(0, $apps[2]['pods']);
});

test('services get their state and usage from their own label', function () use ($fixture) {
    $services = Cluster_overview::read(new Fake_kubernetes($fixture))['services'];

    assert_same([3], array_keys($services));
    assert_same('running', $services[3]['state']['state']);
    assert_same(2 * 1024 ** 3, $services[3]['memory']);
});

test('without metrics-server usage is unknown but requests and states remain', function () use ($fixture) {
    $overview = Cluster_overview::read(new Fake_kubernetes($fixture, false));

    assert_same(false, $overview['metrics']);
    assert_same(null, $overview['nodes'][0]['memory']['used']);
    assert_same(192 * 1024 ** 2, $overview['nodes'][0]['memory']['requested']);
    assert_same(null, $overview['apps'][1]['memory']);
    assert_same('running', $overview['apps'][1]['state']['state']);
});

finish_tests();
