<?php

require_once __DIR__ . '/../../../config/platform.php';
require_once __DIR__ . '/Trongate_config.php';

/**
 * Kubernetes manifests for tenants and their apps, as arrays ready for Kubernetes::apply_all().
 *
 * Naming (C2): namespace "tc-team-{team_id}", per-project resources "p{project_id}-…",
 * MariaDB database + user "tc_p{project_id}".
 *
 * Framework-free: loaded by the worker.
 */
class Manifests {

    const LABEL_PREFIX = 'platform.example.com';
    const MANAGED_BY = 'trongate-cloud';

    // Railpack's FrankenPHP Caddyfile serves ":{$PORT:80}" from /app (or RAILPACK_PHP_ROOT_DIR).
    // We set PORT because restricted pods run as non-root and cannot bind :80.
    const APP_DIR = '/app';
    const APP_PORT = 8080;
    const SERVICE_PORT = 80;
    const RUN_AS_USER = 1000;

    // ephemeral-storage caps the writable layer so one tenant cannot fill a node's disk.
    // No CPU limit for now: a 500m limit halved throughput and CFS throttling added ~55 ms
    // at p99 even for plain PHP. Watch what real apps use before setting one.
    const APP_RESOURCES = [
        'requests' => ['cpu' => '50m', 'memory' => '96Mi', 'ephemeral-storage' => '256Mi'],
        'limits' => ['memory' => '256Mi', 'ephemeral-storage' => '1Gi'],
    ];

    const INIT_RESOURCES = [
        'requests' => ['cpu' => '10m', 'memory' => '32Mi', 'ephemeral-storage' => '64Mi'],
        'limits' => ['cpu' => '200m', 'memory' => '128Mi', 'ephemeral-storage' => '256Mi'],
    ];

    const TENANT_QUOTA = [
        'requests.cpu' => '2',
        'requests.memory' => '4Gi',
        'limits.memory' => '8Gi',
        'requests.ephemeral-storage' => '8Gi',
        'limits.ephemeral-storage' => '30Gi',
        'requests.storage' => '200Gi',
        'pods' => '30',
        'persistentvolumeclaims' => '20',
        'services.loadbalancers' => '0',
        'services.nodeports' => '0',
    ];

    const FILES_SIZE = '10Gi';
    const MAX_USER_CONNECTIONS = 20;
    const SABLIER_SESSION = '15m';
    const SABLIER_PLUGIN = 'sablier';

    // One throwaway BuildKit daemon per build (see buildkitd()), never shared by tenants
    const BUILDKIT_NAMESPACE = 'buildkit';
    const BUILDKIT_IMAGE = 'moby/buildkit:v0.33.1-rootless';
    const BUILDKIT_PORT = 1234;

    // Egress to the internet excludes cluster, node and link-local (metadata) ranges
    const PRIVATE_V4 = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '169.254.0.0/16'];
    const PRIVATE_V6 = ['fc00::/7', 'fe80::/10'];

    static function namespace_name(int $team_id): string {
        return "tc-team-{$team_id}";
    }

    static function app_name(int $project_id): string {
        return "p{$project_id}-app";
    }

    static function database_name(int $project_id): string {
        return "tc_p{$project_id}";
    }

    /**
     * Hostnames routed to a project: "{slug}.PLATFORM_APPS_DOMAIN" plus the custom
     * domain once DNS verified it (project.verified_domain, see db/015)
     */
    static function hostnames(array $project): array {
        $hostnames = [strtolower($project['slug'] . '.' . PLATFORM_APPS_DOMAIN)];
        $domain = strtolower(trim((string) ($project['verified_domain'] ?? '')));

        if ($domain !== '' && !in_array($domain, $hostnames, true)) {
            $hostnames[] = $domain;
        }

        return $hostnames;
    }

    /**
     * Stable per-project MariaDB password derived from APP_KEY (rotating APP_KEY rotates it on next deploy)
     */
    static function database_password(int $project_id): string {
        $key = defined('APP_KEY') ? (string) constant('APP_KEY') : (string) getenv('APP_KEY');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is required to derive database passwords');
        }

        return substr(hash_hmac('sha256', 'database:' . self::database_name($project_id), $key), 0, 48);
    }

    /**
     * Normalised persistent paths (relative to the app dir); rejects anything that escapes it
     *
     * @return list<string>
     */
    static function persistent_paths(array $project): array {
        $paths = $project['persistent_paths'] ?? [];

        if (is_string($paths)) {
            $paths = json_decode($paths, true) ?: [];
        }

        $clean = [];

        foreach ((array) $paths as $path) {
            $path = trim(str_replace('\\', '/', (string) $path), '/');

            if ($path === '') {
                continue;
            }

            if (!preg_match('#^[A-Za-z0-9._/-]+$#', $path) || preg_match('#(^|/)\.{1,2}(/|$)#', $path)) {
                throw new InvalidArgumentException("Invalid persistent path: {$path}");
            }

            $clean[$path] = $path;
        }

        return array_values($clean);
    }

    /**
     * Tenant namespace with quota, limits and network isolation
     */
    static function tenant(int $team_id): array {
        $namespace = self::namespace_name($team_id);
        $labels = self::_labels(['app.kubernetes.io/part-of' => $namespace]);

        $manifests = [];

        $manifests[] = [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $namespace,
                'labels' => $labels + [
                    self::LABEL_PREFIX . '/managed' => 'tenant',
                    self::LABEL_PREFIX . '/tenant' => $namespace,
                    'pod-security.kubernetes.io/enforce' => 'restricted',
                    'pod-security.kubernetes.io/enforce-version' => 'latest',
                ],
            ],
        ];

        $manifests[] = [
            'apiVersion' => 'v1',
            'kind' => 'ResourceQuota',
            'metadata' => self::_metadata('tenant-quota', $namespace, $labels),
            'spec' => ['hard' => self::TENANT_QUOTA],
        ];

        $manifests[] = [
            'apiVersion' => 'v1',
            'kind' => 'LimitRange',
            'metadata' => self::_metadata('tenant-limits', $namespace, $labels),
            'spec' => [
                'limits' => [[
                    'type' => 'Container',
                    'default' => self::APP_RESOURCES['limits'],
                    'defaultRequest' => self::APP_RESOURCES['requests'],
                    'max' => ['memory' => '2Gi', 'ephemeral-storage' => '4Gi'],
                ]],
            ],
        ];

        $network_policy = fn(string $name, array $spec) => [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => self::_metadata($name, $namespace, $labels),
            'spec' => ['podSelector' => (object) []] + $spec,
        ];

        $namespace_peer = fn(array $match_labels) => ['namespaceSelector' => ['matchLabels' => $match_labels]];
        $tcp = fn(int $port) => ['protocol' => 'TCP', 'port' => $port];

        $manifests[] = $network_policy('default-deny', [
            'policyTypes' => ['Ingress', 'Egress'],
        ]);

        $manifests[] = $network_policy('allow-gateway', [
            'policyTypes' => ['Ingress'],
            'ingress' => [[
                'from' => [
                    $namespace_peer([self::LABEL_PREFIX . '/role' => 'gateway']),
                    $namespace_peer([self::LABEL_PREFIX . '/role' => 'sablier']),
                ],
            ]],
        ]);

        $manifests[] = $network_policy('allow-dns', [
            'policyTypes' => ['Egress'],
            'egress' => [[
                'to' => [[
                    'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => 'kube-system']],
                    'podSelector' => ['matchLabels' => ['k8s-app' => 'kube-dns']],
                ]],
                'ports' => [
                    ['protocol' => 'UDP', 'port' => 53],
                    ['protocol' => 'TCP', 'port' => 53],
                ],
            ]],
        ]);

        $manifests[] = $network_policy('allow-shared-services', [
            'policyTypes' => ['Egress'],
            'egress' => [
                [
                    'to' => [$namespace_peer(['kubernetes.io/metadata.name' => 'databases'])],
                    'ports' => [$tcp(3306)],
                ],
                [
                    'to' => [$namespace_peer(['kubernetes.io/metadata.name' => 'cache'])],
                    'ports' => [$tcp(6379)],
                ],
            ],
        ]);

        $manifests[] = $network_policy('allow-internet', [
            'policyTypes' => ['Egress'],
            'egress' => [[
                'to' => [
                    ['ipBlock' => ['cidr' => '0.0.0.0/0', 'except' => self::PRIVATE_V4]],
                    ['ipBlock' => ['cidr' => '::/0', 'except' => self::PRIVATE_V6]],
                ],
                'ports' => [$tcp(80), $tcp(443)],
            ]],
        ]);

        return $manifests;
    }

    /**
     * Everything one project needs inside its tenant namespace, in apply order
     *
     * @param array $project    project row (id, team_id, slug, label, type, domain, persistent_paths, always_on)
     * @param array $deployment deployment row (id, image)
     * @param array $database   database_server row (host, port, operator_name, operator_namespace); optional 'password'
     */
    static function app(array $project, array $deployment, array $database): array {
        $project_id = (int) $project['id'];
        $namespace = self::namespace_name((int) $project['team_id']);
        $name = self::app_name($project_id);
        $secret_name = "p{$project_id}-database";
        $pvc_name = "p{$project_id}-files";
        $db_name = self::database_name($project_id);
        $always_on = !empty($project['always_on']);
        $paths = self::persistent_paths($project);
        $image = (string) ($deployment['image'] ?? '');

        if ($image === '') {
            throw new InvalidArgumentException('Deployment has no image');
        }

        $labels = self::_labels([
            'app.kubernetes.io/name' => $name,
            self::LABEL_PREFIX . '/project-id' => (string) $project_id,
        ]);
        $selector = ['app.kubernetes.io/name' => $name];

        $password = (string) ($database['password'] ?? self::database_password($project_id));
        $database_php = Trongate_config::database_php([
            'host' => $database['host'],
            'port' => (string) ($database['port'] ?? 3306),
            'user' => $db_name,
            'password' => $password,
            'database' => $db_name,
        ], (string) ($project['type'] ?? 'trongate_v2'));

        $base_url = Trongate_config::base_url($project);
        $mariadb_ref = [
            'name' => $database['operator_name'],
            'namespace' => $database['operator_namespace'] ?? 'databases',
        ];

        $manifests = [];

        $manifests[] = [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => self::_metadata($secret_name, $namespace, $labels),
            'type' => 'Opaque',
            'stringData' => [
                'database.php' => $database_php,
                'password' => $password,
            ],
        ];

        $manifests[] = [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => self::_metadata($pvc_name, $namespace, $labels),
            'spec' => [
                'accessModes' => ['ReadWriteMany'],
                'storageClassName' => FILES_STORAGE_CLASS,
                'resources' => ['requests' => ['storage' => self::FILES_SIZE]],
            ],
        ];

        $manifests[] = [
            'apiVersion' => 'k8s.mariadb.com/v1alpha1',
            'kind' => 'Database',
            'metadata' => self::_metadata($name . '-db', $namespace, $labels),
            'spec' => [
                'mariaDbRef' => $mariadb_ref,
                'name' => $db_name,
                'characterSet' => 'utf8mb4',
                'collate' => 'utf8mb4_unicode_ci',
                'cleanupPolicy' => 'Skip', // never drop customer data because a CR went away
            ],
        ];

        $manifests[] = [
            'apiVersion' => 'k8s.mariadb.com/v1alpha1',
            'kind' => 'User',
            'metadata' => self::_metadata($name . '-db-user', $namespace, $labels),
            'spec' => [
                'mariaDbRef' => $mariadb_ref,
                'name' => $db_name,
                'host' => '%',
                'passwordSecretKeyRef' => ['name' => $secret_name, 'key' => 'password'],
                'maxUserConnections' => self::MAX_USER_CONNECTIONS,
                'cleanupPolicy' => 'Delete',
            ],
        ];

        $manifests[] = [
            'apiVersion' => 'k8s.mariadb.com/v1alpha1',
            'kind' => 'Grant',
            'metadata' => self::_metadata($name . '-db-grant', $namespace, $labels),
            'spec' => [
                'mariaDbRef' => $mariadb_ref,
                'privileges' => ['ALL PRIVILEGES'],
                'database' => $db_name,
                'table' => '*',
                'username' => $db_name,
                'host' => '%',
                'cleanupPolicy' => 'Delete',
            ],
        ];

        $manifests[] = self::_deployment($project, $deployment, [
            'name' => $name,
            'namespace' => $namespace,
            'labels' => $labels,
            'selector' => $selector,
            'image' => $image,
            'secret_name' => $secret_name,
            'pvc_name' => $pvc_name,
            'paths' => $paths,
            'always_on' => $always_on,
            'base_url' => $base_url,
            'config_hash' => substr(hash('sha256', $database_php . $base_url . json_encode($paths)), 0, 16),
        ]);

        $manifests[] = [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => self::_metadata($name, $namespace, $labels),
            'spec' => [
                'selector' => $selector,
                'ports' => [[
                    'name' => 'http',
                    'port' => self::SERVICE_PORT,
                    'targetPort' => 'http',
                    'protocol' => 'TCP',
                ]],
            ],
        ];

        $filters = [];

        if (!$always_on) {
            $manifests[] = [
                'apiVersion' => 'traefik.io/v1alpha1',
                'kind' => 'Middleware',
                'metadata' => self::_metadata($name, $namespace, $labels),
                'spec' => [
                    'plugin' => [
                        self::SABLIER_PLUGIN => [
                            'sablierUrl' => SABLIER_URL,
                            'group' => self::sablier_group($project),
                            'sessionDuration' => self::SABLIER_SESSION,
                            'dynamic' => [
                                'displayName' => (string) ($project['label'] ?? $project['slug']),
                                'showDetails' => false,
                                'refreshFrequency' => '2s',
                            ],
                        ],
                    ],
                ],
            ];

            $filters[] = [
                'type' => 'ExtensionRef',
                'extensionRef' => ['group' => 'traefik.io', 'kind' => 'Middleware', 'name' => $name],
            ];
        }

        $rule = [
            'matches' => [['path' => ['type' => 'PathPrefix', 'value' => '/']]],
            'backendRefs' => [['name' => $name, 'port' => self::SERVICE_PORT]],
        ];

        if ($filters) {
            $rule['filters'] = $filters;
        }

        $manifests[] = [
            'apiVersion' => 'gateway.networking.k8s.io/v1',
            'kind' => 'HTTPRoute',
            'metadata' => self::_metadata($name, $namespace, $labels),
            'spec' => [
                'parentRefs' => [[
                    'group' => 'gateway.networking.k8s.io',
                    'kind' => 'Gateway',
                    'name' => PLATFORM_GATEWAY_NAME,
                    'namespace' => PLATFORM_GATEWAY_NAMESPACE,
                ]],
                'hostnames' => self::hostnames($project),
                'rules' => [$rule],
            ],
        ];

        return $manifests;
    }

    /**
     * Sablier group shared by the Deployment labels and the Middleware (label-value safe, cluster unique)
     */
    static function sablier_group(array $project): string {
        return self::namespace_name((int) $project['team_id']) . '-p' . (int) $project['id'];
    }

    /**
     * A rootless BuildKit daemon Pod for one deployment's build. The worker
     * creates it, builds against tcp://{podIP}:BUILDKIT_PORT and deletes it, so
     * untrusted build steps never share a daemon, PID namespace, local cache or
     * forwarded credentials with another tenant's build. The registry cache
     * (--import-cache/--export-cache) is the only cache that outlives it.
     *
     * Rootless BuildKit needs Unconfined seccomp/AppArmor (the buildkit namespace
     * is "privileged" for that); --oci-worker-no-process-sandbox is safe here
     * because nothing else runs in the daemon.
     */
    static function buildkitd(int $deployment_id, int $timeout_seconds): array {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => self::_metadata(self::buildkitd_name($deployment_id), self::BUILDKIT_NAMESPACE, self::_labels([
                // buildkit/buildkitd.yaml's NetworkPolicy selects this label
                'app.kubernetes.io/name' => 'buildkitd',
                self::LABEL_PREFIX . '/deployment-id' => (string) $deployment_id,
            ])),
            'spec' => [
                'restartPolicy' => 'Never',
                'activeDeadlineSeconds' => $timeout_seconds,
                'terminationGracePeriodSeconds' => 10,
                'automountServiceAccountToken' => false,
                'enableServiceLinks' => false,
                'containers' => [[
                    'name' => 'buildkitd',
                    'image' => self::BUILDKIT_IMAGE,
                    'args' => [
                        '--addr', 'unix:///run/user/1000/buildkit/buildkitd.sock',
                        '--addr', 'tcp://0.0.0.0:' . self::BUILDKIT_PORT,
                        '--oci-worker-no-process-sandbox',
                    ],
                    'ports' => [['name' => 'buildkit', 'containerPort' => self::BUILDKIT_PORT, 'protocol' => 'TCP']],
                    'readinessProbe' => [
                        'exec' => ['command' => ['buildctl', 'debug', 'workers']],
                        'initialDelaySeconds' => 2,
                        'periodSeconds' => 2,
                    ],
                    'securityContext' => [
                        'seccompProfile' => ['type' => 'Unconfined'],
                        'appArmorProfile' => ['type' => 'Unconfined'],
                        'runAsUser' => 1000,
                        'runAsGroup' => 1000,
                    ],
                    'resources' => [
                        'requests' => ['cpu' => '500m', 'memory' => '1Gi', 'ephemeral-storage' => '2Gi'],
                        'limits' => ['cpu' => '2', 'memory' => '4Gi', 'ephemeral-storage' => '20Gi'],
                    ],
                    'volumeMounts' => [['name' => 'cache', 'mountPath' => '/home/user/.local/share/buildkit']],
                ]],
                'volumes' => [['name' => 'cache', 'emptyDir' => ['sizeLimit' => '20Gi']]],
            ],
        ];
    }

    static function buildkitd_name(int $deployment_id): string {
        return "buildkitd-d{$deployment_id}";
    }

    static function _deployment(array $project, array $deployment, array $o): array {
        $container_security = [
            'allowPrivilegeEscalation' => false,
            'runAsNonRoot' => true,
            'capabilities' => ['drop' => ['ALL']],
            'seccompProfile' => ['type' => 'RuntimeDefault'],
        ];

        // The FrankenPHP binary carries the cap_net_bind_service file capability.
        // With every capability dropped and no privilege escalation, exec of such
        // a binary fails ("exec: frankenphp: Operation not permitted"), even on a
        // port above 1024. Keeping just this one in the bounding set fixes it and
        // is still allowed by restricted Pod Security.
        $app_security = $container_security;
        $app_security['capabilities']['add'] = ['NET_BIND_SERVICE'];

        $app_dir = self::APP_DIR;
        $config_script = "set -eu\ncp {$app_dir}/config/config.php /work/config.php\n"
            . Trongate_config::patch_script(['BASE_URL' => $o['base_url'], 'ENV' => 'prod'], '/work/config.php');

        $volumes = [
            ['name' => 'config', 'emptyDir' => ['sizeLimit' => '1Mi']],
            ['name' => 'tmp', 'emptyDir' => ['sizeLimit' => '256Mi']],
            ['name' => 'database', 'secret' => [
                'secretName' => $o['secret_name'],
                'items' => [['key' => 'database.php', 'path' => 'database.php']],
            ]],
        ];

        $init_containers = [[
            'name' => 'config',
            'image' => $o['image'],
            'command' => ['sh', '-c', $config_script],
            'volumeMounts' => [['name' => 'config', 'mountPath' => '/work']],
            'securityContext' => $container_security,
            'resources' => self::INIT_RESOURCES,
        ]];

        $mounts = [
            ['name' => 'config', 'mountPath' => "{$app_dir}/config/config.php", 'subPath' => 'config.php', 'readOnly' => true],
            ['name' => 'database', 'mountPath' => "{$app_dir}/config/database.php", 'subPath' => 'database.php', 'readOnly' => true],
            ['name' => 'tmp', 'mountPath' => '/tmp'],
        ];

        if ($o['paths']) {
            $volumes[] = ['name' => 'files', 'persistentVolumeClaim' => ['claimName' => $o['pvc_name']]];

            $seed = ['set -eu'];

            foreach ($o['paths'] as $path) {
                $source = Trongate_config::sh_quote("{$app_dir}/{$path}");
                $target = Trongate_config::sh_quote("/files/{$path}");
                $seed[] = "mkdir -p {$target}";
                $seed[] = "if [ -d {$source} ] && [ -z \"$(ls -A {$target})\" ]; then cp -R {$source}/. {$target}/; fi";
                $mounts[] = ['name' => 'files', 'mountPath' => "{$app_dir}/{$path}", 'subPath' => $path];
            }

            $init_containers[] = [
                'name' => 'seed-files',
                'image' => $o['image'],
                'command' => ['sh', '-c', implode("\n", $seed) . "\n"],
                'volumeMounts' => [['name' => 'files', 'mountPath' => '/files']],
                'securityContext' => $container_security,
                'resources' => self::INIT_RESOURCES,
            ];
        }

        $labels = $o['labels'];

        if (!$o['always_on']) {
            // Sablier's Kubernetes provider discovers workloads by these labels
            $labels['sablier.enable'] = 'true';
            $labels['sablier.group'] = self::sablier_group($project);
        }

        $spec = [
            'revisionHistoryLimit' => 3,
            'selector' => ['matchLabels' => $o['selector']],
            'template' => [
                'metadata' => [
                    'labels' => $o['labels'],
                    'annotations' => [
                        self::LABEL_PREFIX . '/deployment-id' => (string) ($deployment['id'] ?? ''),
                        self::LABEL_PREFIX . '/config-hash' => $o['config_hash'],
                    ],
                ],
                'spec' => [
                    'automountServiceAccountToken' => false,
                    'enableServiceLinks' => false,
                    'securityContext' => [
                        'runAsNonRoot' => true,
                        'runAsUser' => self::RUN_AS_USER,
                        'runAsGroup' => self::RUN_AS_USER,
                        'fsGroup' => self::RUN_AS_USER,
                        'seccompProfile' => ['type' => 'RuntimeDefault'],
                    ],
                    'initContainers' => $init_containers,
                    'containers' => [[
                        'name' => 'app',
                        'image' => $o['image'],
                        'ports' => [['name' => 'http', 'containerPort' => self::APP_PORT, 'protocol' => 'TCP']],
                        'env' => [
                            ['name' => 'PORT', 'value' => (string) self::APP_PORT],
                            // Caddy needs writable config/data dirs; the image's defaults are root-owned
                            ['name' => 'XDG_CONFIG_HOME', 'value' => '/tmp/caddy/config'],
                            ['name' => 'XDG_DATA_HOME', 'value' => '/tmp/caddy/data'],
                        ],
                        'volumeMounts' => $mounts,
                        'readinessProbe' => [
                            'tcpSocket' => ['port' => 'http'],
                            'initialDelaySeconds' => 1,
                            'periodSeconds' => 3,
                        ],
                        'securityContext' => $app_security,
                        'resources' => self::APP_RESOURCES,
                    ]],
                    'volumes' => $volumes,
                ],
            ],
        ];

        // Sablier owns replicas for scale-to-zero apps; only pin them when always on
        if ($o['always_on']) {
            $spec = ['replicas' => 1] + $spec;
        }

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => self::_metadata($o['name'], $o['namespace'], $labels),
            'spec' => $spec,
        ];
    }

    static function _metadata(string $name, string $namespace, array $labels): array {
        return ['name' => $name, 'namespace' => $namespace, 'labels' => $labels];
    }

    static function _labels(array $extra = []): array {
        return ['app.kubernetes.io/managed-by' => self::MANAGED_BY] + $extra;
    }
}
