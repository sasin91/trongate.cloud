<?php

/**
 * A small cluster as the API server and metrics-server answer it, by kind:
 * two nodes (one under memory pressure), app 1 with a queue process, service 3,
 * a finished job and a pod that can't be scheduled.
 */

$pod = function (string $namespace, string $name, ?string $node, array $labels, array $requests, string $phase = 'Running', array $conditions = []): array {
    return [
        'metadata' => ['namespace' => $namespace, 'name' => $name, 'labels' => $labels],
        'spec' => ['nodeName' => $node, 'containers' => [['resources' => ['requests' => $requests]]]],
        'status' => ['phase' => $phase, 'conditions' => $conditions, 'containerStatuses' => []],
    ];
};

$node = function (string $name, array $conditions, string $cpu, string $memory): array {
    return [
        'metadata' => ['name' => $name, 'labels' => ['node.kubernetes.io/instance-type' => 'cx33', 'topology.kubernetes.io/zone' => 'fsn1']],
        'spec' => [],
        'status' => [
            'allocatable' => ['cpu' => $cpu, 'memory' => $memory, 'pods' => '110'],
            'conditions' => $conditions,
        ],
    ];
};

$app_labels = ['app.kubernetes.io/managed-by' => 'trongate-cloud', 'app.kubernetes.io/name' => 'p1-app', 'platform.example.com/project-id' => '1'];
$process_labels = ['app.kubernetes.io/managed-by' => 'trongate-cloud', 'app.kubernetes.io/name' => 'p1-queue', 'platform.example.com/project-id' => '1', 'app.kubernetes.io/component' => 'process', 'trongate.cloud/process' => 'queue'];
$service_labels = ['app.kubernetes.io/managed-by' => 'trongate-cloud', 'app.kubernetes.io/name' => 's3-service', 'platform.example.com/service-id' => '3'];

return [
    'Node' => [
        $node('node-b', [['type' => 'Ready', 'status' => 'True'], ['type' => 'MemoryPressure', 'status' => 'True']], '4', '7901960Ki'),
        $node('node-a', [['type' => 'Ready', 'status' => 'True'], ['type' => 'MemoryPressure', 'status' => 'False']], '3800m', '8Gi'),
    ],
    'Pod' => [
        $pod('project-1', 'p1-app-abc', 'node-a', $app_labels, ['cpu' => '50m', 'memory' => '128Mi']),
        $pod('project-1', 'p1-queue-def', 'node-a', $process_labels, ['cpu' => '25m', 'memory' => '64Mi']),
        $pod('project-2', 's3-service-ghi', 'node-b', $service_labels, ['cpu' => '50m', 'memory' => '3Gi']),
        $pod('trongate-cloud', 'build-1', 'node-b', [], ['cpu' => '2', 'memory' => '4Gi'], 'Succeeded'),
        $pod('project-2', 'big-xyz', null, [], ['memory' => '9Gi'], 'Pending', [['type' => 'PodScheduled', 'status' => 'False', 'message' => '0/2 nodes are available: 2 Insufficient memory.']]),
    ],
    'Deployment' => [
        ['metadata' => ['name' => 'p1-app', 'labels' => $app_labels], 'spec' => ['replicas' => 1], 'status' => ['readyReplicas' => 1]],
        ['metadata' => ['name' => 'p1-queue', 'labels' => $process_labels], 'spec' => ['replicas' => 1], 'status' => ['readyReplicas' => 0]],
        ['metadata' => ['name' => 's3-service', 'labels' => $service_labels], 'spec' => ['replicas' => 1], 'status' => ['readyReplicas' => 1]],
        ['metadata' => ['name' => 'p2-app', 'labels' => ['app.kubernetes.io/name' => 'p2-app', 'platform.example.com/project-id' => '2']], 'spec' => ['replicas' => 0], 'status' => []],
    ],
    'NodeMetrics' => [
        ['metadata' => ['name' => 'node-a'], 'usage' => ['cpu' => '412345678n', 'memory' => '2097152Ki']],
        ['metadata' => ['name' => 'node-b'], 'usage' => ['cpu' => '1', 'memory' => '7Gi']],
    ],
    'PodMetrics' => [
        ['metadata' => ['namespace' => 'project-1', 'name' => 'p1-app-abc'], 'containers' => [['usage' => ['cpu' => '2000000n', 'memory' => '40Mi']]]],
        ['metadata' => ['namespace' => 'project-1', 'name' => 'p1-queue-def'], 'containers' => [['usage' => ['cpu' => '1m', 'memory' => '22Mi']]]],
        ['metadata' => ['namespace' => 'project-2', 'name' => 's3-service-ghi'], 'containers' => [['usage' => ['cpu' => '30m', 'memory' => '2Gi']]]],
    ],
];
