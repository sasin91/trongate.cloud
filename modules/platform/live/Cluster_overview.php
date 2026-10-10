<?php

require_once __DIR__ . '/App_state.php';
require_once __DIR__ . '/../manifests/Project_service.php';

/**
 * The cluster at a glance for the tg-admin overview: each node's pressure
 * (what its pods ask for and use against what it can give) and what every
 * app and service is doing and using right now.
 *
 * Framework-free and read live on each request, nothing stored: nodes and
 * pods from the API server, usage from metrics-server (metrics.k8s.io), with
 * the web app's read-only service account (deploy/k8s/platform/app-rbac.yaml).
 */
class Cluster_overview {

    /** A page render must not hang on an unreachable API server */
    const TIMEOUT_SECONDS = 3;

    /** Node conditions that mean the kubelet is short of something */
    const PRESSURE_CONDITIONS = ['MemoryPressure', 'DiskPressure', 'PIDPressure', 'NetworkUnavailable'];

    /**
     * @return array{
     *     nodes: list<array{name: string, ready: bool, pressure: list<string>, type: string, zone: string,
     *         cpu: array{allocatable: int, requested: int, used: ?int},
     *         memory: array{allocatable: int, requested: int, used: ?int},
     *         pods: array{running: int, capacity: int}}>,
     *     pending: list<array{namespace: string, name: string, reason: string}>,
     *     apps: array<int, array{state: array{state: string, label: string, detail: string}, memory: ?int, cpu: ?int, pods: int}>,
     *     services: array<int, array{state: array{state: string, label: string, detail: string}, memory: ?int, cpu: ?int, pods: int}>,
     *     metrics: bool
     * }
     */
    static function read(Kubernetes $kube): array {
        $kube = $kube->with_timeout(self::TIMEOUT_SECONDS);

        $nodes = $kube->list('v1', 'Node', null);
        $pods = $kube->list('v1', 'Pod', null);
        $deployments = $kube->list('apps/v1', 'Deployment', null, 'app.kubernetes.io/managed-by=' . Manifests::MANAGED_BY);

        // Usage needs metrics-server; the rest of the page works without it
        try {
            $node_metrics = $kube->list('metrics.k8s.io/v1beta1', 'NodeMetrics', null);
            $pod_metrics = $kube->list('metrics.k8s.io/v1beta1', 'PodMetrics', null);
            $metrics = true;
        } catch (Throwable $e) {
            $node_metrics = $pod_metrics = [];
            $metrics = false;
        }

        return [
            'nodes' => self::_nodes($nodes, $pods, $node_metrics, $metrics),
            'pending' => self::_pending($pods),
            'apps' => self::_workloads($deployments, $pods, $pod_metrics, $metrics, Manifests::APP_LABEL),
            'services' => self::_workloads($deployments, $pods, $pod_metrics, $metrics, Project_service::LABEL),
            'metrics' => $metrics,
        ];
    }

    static function _nodes(array $nodes, array $pods, array $node_metrics, bool $metrics): array {
        $usage = [];
        foreach ($node_metrics as $metric) {
            $usage[$metric['metadata']['name'] ?? ''] = $metric['usage'] ?? [];
        }

        $requested = [];
        foreach ($pods as $pod) {
            $node = (string) ($pod['spec']['nodeName'] ?? '');

            if ($node === '' || !self::_is_active($pod)) {
                continue;
            }

            $requested[$node] ??= ['cpu' => 0, 'memory' => 0, 'pods' => 0];
            $requested[$node]['pods']++;

            foreach ($pod['spec']['containers'] ?? [] as $container) {
                $requested[$node]['cpu'] += self::cpu_millis((string) ($container['resources']['requests']['cpu'] ?? '0'));
                $requested[$node]['memory'] += self::bytes((string) ($container['resources']['requests']['memory'] ?? '0'));
            }
        }

        $result = [];
        foreach ($nodes as $node) {
            $name = (string) ($node['metadata']['name'] ?? '');
            $labels = $node['metadata']['labels'] ?? [];
            $allocatable = $node['status']['allocatable'] ?? [];
            $ready = false;
            $pressure = [];

            foreach ($node['status']['conditions'] ?? [] as $condition) {
                $type = (string) ($condition['type'] ?? '');

                if ($type === 'Ready') {
                    $ready = ($condition['status'] ?? '') === 'True';
                } elseif (in_array($type, self::PRESSURE_CONDITIONS, true) && ($condition['status'] ?? '') === 'True') {
                    $pressure[] = $type;
                }
            }

            if (!empty($node['spec']['unschedulable'])) {
                $pressure[] = 'Cordoned';
            }

            $result[] = [
                'name' => $name,
                'ready' => $ready,
                'pressure' => $pressure,
                'type' => (string) ($labels['node.kubernetes.io/instance-type'] ?? ''),
                'zone' => (string) ($labels['topology.kubernetes.io/zone'] ?? ''),
                'cpu' => [
                    'allocatable' => self::cpu_millis((string) ($allocatable['cpu'] ?? '0')),
                    'requested' => $requested[$name]['cpu'] ?? 0,
                    'used' => $metrics && isset($usage[$name]['cpu']) ? self::cpu_millis((string) $usage[$name]['cpu']) : null,
                ],
                'memory' => [
                    'allocatable' => self::bytes((string) ($allocatable['memory'] ?? '0')),
                    'requested' => $requested[$name]['memory'] ?? 0,
                    'used' => $metrics && isset($usage[$name]['memory']) ? self::bytes((string) $usage[$name]['memory']) : null,
                ],
                'pods' => [
                    'running' => $requested[$name]['pods'] ?? 0,
                    'capacity' => (int) ($allocatable['pods'] ?? 0),
                ],
            ];
        }

        usort($result, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $result;
    }

    /**
     * Pods the scheduler could not place: the clearest sign the cluster is full
     */
    static function _pending(array $pods): array {
        $pending = [];

        foreach ($pods as $pod) {
            if (($pod['status']['phase'] ?? '') !== 'Pending' || !empty($pod['spec']['nodeName'])) {
                continue;
            }

            $reason = '';
            foreach ($pod['status']['conditions'] ?? [] as $condition) {
                if (($condition['type'] ?? '') === 'PodScheduled' && ($condition['status'] ?? '') === 'False') {
                    $reason = (string) ($condition['message'] ?? $condition['reason'] ?? '');
                }
            }

            $pending[] = [
                'namespace' => (string) ($pod['metadata']['namespace'] ?? ''),
                'name' => (string) ($pod['metadata']['name'] ?? ''),
                'reason' => mb_strimwidth($reason, 0, 300, '…'),
            ];
        }

        return $pending;
    }

    /**
     * State and usage per app (or service) id, from the label that names it.
     * The state is the app's own Deployment's (processes and services have
     * their own); usage adds up every pod carrying the id, processes included.
     *
     * @return array<int, array{state: array{state: string, label: string, detail: string}, memory: ?int, cpu: ?int, pods: int}>
     */
    static function _workloads(array $deployments, array $pods, array $pod_metrics, bool $metrics, string $label): array {
        $usage = [];
        foreach ($pod_metrics as $metric) {
            $key = ($metric['metadata']['namespace'] ?? '') . '/' . ($metric['metadata']['name'] ?? '');
            $usage[$key] = ['cpu' => 0, 'memory' => 0];

            foreach ($metric['containers'] ?? [] as $container) {
                $usage[$key]['cpu'] += self::cpu_millis((string) ($container['usage']['cpu'] ?? '0'));
                $usage[$key]['memory'] += self::bytes((string) ($container['usage']['memory'] ?? '0'));
            }
        }

        $pods_by_name = [];
        $result = [];
        foreach ($pods as $pod) {
            $id = (int) ($pod['metadata']['labels'][$label] ?? 0);

            if ($id <= 0 || !self::_is_active($pod)) {
                continue;
            }

            $pods_by_name[$pod['metadata']['labels']['app.kubernetes.io/name'] ?? ''][] = $pod;
            $result[$id] ??= ['state' => App_state::_make('not_running'), 'memory' => $metrics ? 0 : null, 'cpu' => $metrics ? 0 : null, 'pods' => 0];
            $result[$id]['pods']++;

            $key = ($pod['metadata']['namespace'] ?? '') . '/' . ($pod['metadata']['name'] ?? '');
            if ($metrics && isset($usage[$key])) {
                $result[$id]['memory'] += $usage[$key]['memory'];
                $result[$id]['cpu'] += $usage[$key]['cpu'];
            }
        }

        foreach ($deployments as $deployment) {
            $labels = $deployment['metadata']['labels'] ?? [];
            $id = (int) ($labels[$label] ?? 0);
            $name = (string) ($deployment['metadata']['name'] ?? '');

            // An app's own Deployment, not its processes'; a service has one
            $own = $label === Manifests::APP_LABEL ? $name === Manifests::app_name($id) : true;

            if ($id <= 0 || !$own) {
                continue;
            }

            $result[$id] ??= ['state' => App_state::_make('not_running'), 'memory' => $metrics ? 0 : null, 'cpu' => $metrics ? 0 : null, 'pods' => 0];
            $result[$id]['state'] = App_state::_state($deployment, $pods_by_name[$labels['app.kubernetes.io/name'] ?? ''] ?? []);
        }

        return $result;
    }

    /**
     * Pods that hold their requests: everything but finished ones
     */
    static function _is_active(array $pod): bool {
        return !in_array($pod['status']['phase'] ?? '', ['Succeeded', 'Failed'], true);
    }

    /**
     * A Kubernetes CPU quantity ("2", "250m", "1500000n") in millicores
     */
    static function cpu_millis(string $quantity): int {
        if (!preg_match('/^([0-9.]+)([numk]?)$/', trim($quantity), $m)) {
            return 0;
        }

        $factor = ['n' => 1e-6, 'u' => 1e-3, 'm' => 1, '' => 1000, 'k' => 1e6][$m[2]];

        return (int) round((float) $m[1] * $factor);
    }

    /**
     * A Kubernetes memory quantity ("512Mi", "1Gi", "128974848", "1G") in bytes
     */
    static function bytes(string $quantity): int {
        if (!preg_match('/^([0-9.]+)(?:e([0-9]+))?(Ki|Mi|Gi|Ti|k|K|M|G|T|m)?$/', trim($quantity), $m)) {
            return 0;
        }

        $factor = [
            '' => 1, 'm' => 1e-3,
            'k' => 1e3, 'K' => 1e3, 'M' => 1e6, 'G' => 1e9, 'T' => 1e12,
            'Ki' => 1024, 'Mi' => 1024 ** 2, 'Gi' => 1024 ** 3, 'Ti' => 1024 ** 4,
        ][$m[3] ?? ''];

        return (int) round((float) $m[1] * (10 ** (int) ($m[2] ?? 0)) * $factor);
    }
}
