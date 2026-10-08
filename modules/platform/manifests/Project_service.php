<?php

require_once __DIR__ . '/Manifests.php';

/**
 * A project's services: a container image that runs next to the project's
 * apps (a model server, a search engine, a queue), always on, reachable by
 * the project's own pods only. Each service is a Deployment, a ClusterIP
 * Service and two NetworkPolicies named s{id} in the project's namespace; the
 * apps get {NAME}_URL (http://s{id}:{port}) on their next deploy
 * (Platform_task::apply).
 *
 * Its environment variables are stored like an app's (App_env): encrypted in
 * the secrets table as (module 'service', service id, service 'env').
 *
 * Framework-free: the projects and mcp modules validate with it, the worker
 * builds manifests with it.
 */
final class Project_service {

    const MODULE = 'service';
    const SERVICE = 'env';

    const MAX_PER_PROJECT = 5;
    const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';
    const MIN_MEMORY_MB = 64;
    const MAX_MEMORY_MB = 4096;
    const DEFAULT_MEMORY_MB = 512;
    const DEFAULT_PORT = 8080;
    const MAX_IMAGE_LENGTH = 500;
    const ROLLOUT_TIMEOUT_SECONDS = 600;

    const LABEL = Manifests::LABEL_PREFIX . '/service-id';

    /**
     * @return string|null why the name cannot be used, or null when it can
     */
    static function name_error(string $name): ?string {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return 'Use a lowercase name of up to 40 letters, digits and underscores, starting with a letter (e.g. search).';
        }

        return null;
    }

    /**
     * A full image reference with a fixed version: registry/name:tag or
     * name@sha256:digest, never :latest, so a restart never changes what runs.
     *
     * @return string|null why the image cannot be used, or null when it can
     */
    static function image_error(string $image): ?string {
        if ($image === '' || strlen($image) > self::MAX_IMAGE_LENGTH) {
            return 'Enter an image of up to ' . self::MAX_IMAGE_LENGTH . ' characters.';
        }

        if (preg_match('#^[a-z0-9]([a-z0-9._:/-]*[a-z0-9])?(:[A-Za-z0-9_][A-Za-z0-9._-]{0,127}|@sha256:[0-9a-f]{64})$#', $image) !== 1) {
            return 'Enter an image with a version, such as ghcr.io/owner/name:1.2.3 or name@sha256:….';
        }

        if (preg_match('#:latest$#', $image) === 1) {
            return 'Pin a version instead of :latest, so a restart never changes what runs.';
        }

        return null;
    }

    static function port_error(int $port): ?string {
        return $port >= 1024 && $port <= 65535 ? null : 'The port must be between 1024 and 65535 (the container runs without root).';
    }

    static function memory_error(int $memory_mb): ?string {
        return $memory_mb >= self::MIN_MEMORY_MB && $memory_mb <= self::MAX_MEMORY_MB
            ? null
            : 'Memory must be between ' . self::MIN_MEMORY_MB . ' and ' . self::MAX_MEMORY_MB . ' MB.';
    }

    /**
     * The service's Kubernetes name: s{id}, which is also its host name
     * inside the namespace
     */
    static function object_name(int $service_id): string {
        return "s{$service_id}";
    }

    /**
     * The variable the project's apps read the service's URL from: search → SEARCH_URL
     */
    static function url_variable(string $name): string {
        return strtoupper($name) . '_URL';
    }

    static function url(int $service_id, int $port): string {
        return 'http://' . self::object_name($service_id) . ':' . $port;
    }

    /**
     * {NAME}_URL for each of the project's live services, for its apps' environment
     *
     * @param iterable<array{id: int|string, name: string, port: int|string}> $services
     * @return array<string, string>
     */
    static function app_urls(iterable $services): array {
        $urls = [];

        foreach ($services as $service) {
            $urls[self::url_variable((string) $service['name'])] = self::url((int) $service['id'], (int) $service['port']);
        }

        return $urls;
    }

    static function env_secret_name(int $service_id): string {
        return self::object_name($service_id) . '-env';
    }

    /**
     * Ownership labels of every object manifests() emits for a service
     */
    static function labels(int $service_id): array {
        return Manifests::_labels([
            'app.kubernetes.io/name' => self::object_name($service_id),
            self::LABEL => (string) $service_id,
        ]);
    }

    /**
     * Whether a live object is this service's: in the given tenant namespace
     * with all of labels(). destroy_service checks this before deleting.
     */
    static function owned_by_service(array $object, string $namespace, int $service_id): bool {
        $metadata = $object['metadata'] ?? [];

        if (!Manifests::is_tenant_namespace($namespace) || ($metadata['namespace'] ?? null) !== $namespace) {
            return false;
        }

        foreach (self::labels($service_id) as $key => $value) {
            if (($metadata['labels'][$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * [apiVersion, kind, name] of everything manifests() creates, in teardown
     * order: traffic first, then the workload, then config
     *
     * @return list<array{string, string, string}>
     */
    static function objects(int $service_id): array {
        $name = self::object_name($service_id);

        return [
            ['networking.k8s.io/v1', 'NetworkPolicy', "{$name}-to"],
            ['networking.k8s.io/v1', 'NetworkPolicy', "{$name}-from"],
            ['v1', 'Service', $name],
            ['apps/v1', 'Deployment', $name],
            ['v1', 'Secret', self::env_secret_name($service_id)],
        ];
    }

    /**
     * A service's manifests
     *
     * @param array $service    service row (id, name, image, port, memory_mb)
     * @param string $namespace the project's namespace (project.namespace)
     * @param array<string, string> $env its environment variables
     */
    static function manifests(array $service, string $namespace, array $env = []): array {
        if (!Manifests::is_tenant_namespace($namespace)) {
            throw new InvalidArgumentException("Not a tenant namespace: '{$namespace}'");
        }

        $id = (int) $service['id'];
        $name = self::object_name($id);
        $port = (int) $service['port'];
        $memory = (int) $service['memory_mb'] . 'Mi';
        $labels = self::labels($id);
        $selector = ['app.kubernetes.io/name' => $name, self::LABEL => (string) $id];
        $secret_name = self::env_secret_name($id);
        $tcp = ['protocol' => 'TCP', 'port' => $port];

        $container_security = [
            'allowPrivilegeEscalation' => false,
            'runAsNonRoot' => true,
            'capabilities' => ['drop' => ['ALL']],
            'seccompProfile' => ['type' => 'RuntimeDefault'],
        ];

        return [
            // 'data', not 'stringData', so a variable left out of the next apply is removed
            [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => Manifests::_metadata($secret_name, $namespace, $labels),
                'type' => 'Opaque',
                'data' => (object) array_map('base64_encode', $env),
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => Manifests::_metadata($name, $namespace, $labels),
                'spec' => [
                    // Always on: Sablier only wakes what is reached through
                    // Traefik, and apps call a service from inside the cluster
                    'replicas' => 1,
                    'revisionHistoryLimit' => 3,
                    // One copy at a time: a model service may not fit twice
                    'strategy' => ['type' => 'Recreate'],
                    'selector' => ['matchLabels' => $selector],
                    'template' => [
                        'metadata' => [
                            'labels' => $labels,
                            // Changed variables restart it: envFrom is only read at container start
                            'annotations' => [Manifests::LABEL_PREFIX . '/config-hash' => substr(hash('sha256', json_encode($env)), 0, 16)],
                        ],
                        'spec' => [
                            'automountServiceAccountToken' => false,
                            'enableServiceLinks' => false,
                            'securityContext' => [
                                'runAsNonRoot' => true,
                                'runAsUser' => Manifests::RUN_AS_USER,
                                'runAsGroup' => Manifests::RUN_AS_USER,
                                'fsGroup' => Manifests::RUN_AS_USER,
                                'seccompProfile' => ['type' => 'RuntimeDefault'],
                            ],
                            'containers' => [[
                                'name' => 'service',
                                'image' => (string) $service['image'],
                                'ports' => [['name' => 'service', 'containerPort' => $port, 'protocol' => 'TCP']],
                                'envFrom' => [['secretRef' => ['name' => $secret_name]]],
                                // The image's own default for PORT loses to this,
                                // so images that read PORT listen where we route
                                'env' => [['name' => 'PORT', 'value' => (string) $port]],
                                'volumeMounts' => [['name' => 'tmp', 'mountPath' => '/tmp']],
                                'readinessProbe' => [
                                    'tcpSocket' => ['port' => 'service'],
                                    'periodSeconds' => 5,
                                ],
                                'securityContext' => $container_security,
                                'resources' => [
                                    'requests' => ['cpu' => '50m', 'memory' => $memory, 'ephemeral-storage' => '128Mi'],
                                    'limits' => ['memory' => $memory, 'ephemeral-storage' => '1Gi'],
                                ],
                            ]],
                            'volumes' => [['name' => 'tmp', 'emptyDir' => ['sizeLimit' => '512Mi']]],
                        ],
                    ],
                ],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => Manifests::_metadata($name, $namespace, $labels),
                'spec' => [
                    'selector' => $selector,
                    'ports' => [['name' => 'service', 'port' => $port, 'targetPort' => 'service', 'protocol' => 'TCP']],
                ],
            ],
            // The tenant's default-deny covers both directions: the service
            // takes its port from the project's own pods, and they may send to it
            [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'NetworkPolicy',
                'metadata' => Manifests::_metadata("{$name}-to", $namespace, $labels),
                'spec' => [
                    'podSelector' => ['matchLabels' => $selector],
                    'policyTypes' => ['Ingress'],
                    'ingress' => [['from' => [['podSelector' => (object) []]], 'ports' => [$tcp]]],
                ],
            ],
            [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'NetworkPolicy',
                'metadata' => Manifests::_metadata("{$name}-from", $namespace, $labels),
                'spec' => [
                    'podSelector' => (object) [],
                    'policyTypes' => ['Egress'],
                    'egress' => [['to' => [['podSelector' => ['matchLabels' => $selector]]], 'ports' => [$tcp]]],
                ],
            ],
        ];
    }
}
