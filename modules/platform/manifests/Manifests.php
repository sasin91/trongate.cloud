<?php

require_once __DIR__ . '/../../../config/platform.php';
require_once __DIR__ . '/Trongate_config.php';
require_once __DIR__ . '/Resource_tiers.php';

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

    /** Every per-project object carries this label (the project id) next to managed-by */
    const PROJECT_LABEL = self::LABEL_PREFIX . '/project-id';

    // Railpack's FrankenPHP Caddyfile serves ":{$PORT:80}" from /app (or RAILPACK_PHP_ROOT_DIR).
    // We set PORT because restricted pods run as non-root and cannot bind :80.
    const APP_DIR = '/app';
    const APP_PORT = 8080;
    const SERVICE_PORT = 80;
    const RUN_AS_USER = 1000;

    // The app container's CPU/memory come from its project's rung on the
    // Resource_tiers ladder. ephemeral-storage is the same for every rung: it caps
    // the writable layer so one tenant cannot fill a node's disk.
    const APP_EPHEMERAL_STORAGE = ['requests' => '256Mi', 'limits' => '1Gi'];

    const INIT_RESOURCES = [
        'requests' => ['cpu' => '10m', 'memory' => '32Mi', 'ephemeral-storage' => '64Mi'],
        'limits' => ['cpu' => '200m', 'memory' => '128Mi', 'ephemeral-storage' => '256Mi'],
    ];

    // Shared by every project in the team: projects are refused a tier (or a new
    // project) that would not fit (quota_shortfall). cpu/memory must admit two
    // pods of the top Resource_tiers rung (_assert_quota_fits_ladder).
    // requests.memory stays well below the memory the cluster has left for all
    // tenants (~3.6 GiB of ~11 GiB allocatable), so one team cannot reserve it
    // all and leave every other team's pods Pending. 2Gi costs a team nothing:
    // every rung requests 128Mi per 500m of CPU limit, so limits.cpu 8 already
    // stops a team at 2Gi of memory requests.
    const TENANT_QUOTA = [
        'requests.cpu' => '2',
        'requests.memory' => '2Gi',
        'limits.cpu' => '8',
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

    // Custom domains (see domain_gateway()). Gateway listener ports are Traefik's
    // entrypoint ports (web :8000, websecure :8443), not the Service's 80/443.
    const GATEWAY_HTTP_PORT = 8000;
    const GATEWAY_HTTPS_PORT = 8443;
    // Per-namespace HTTP-only Gateway that PLATFORM_DOMAIN_ISSUER's HTTP-01
    // challenge routes attach to. The issuer names it without a namespace, so
    // each challenge lands on the Gateway in its own namespace. The name is also
    // in worker-admission-policy.yaml and the issuer (infra repo, cluster-issuers.yaml).
    const ACME_GATEWAY = 'acme-http01';
    const DOMAIN_COMPONENT = 'custom-domain';

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
     * Hostnames routed to a project: "{slug}.PLATFORM_APPS_DOMAIN" plus its routed
     * custom domains (custom_domains())
     */
    static function hostnames(array $project): array {
        $hostnames = [strtolower($project['slug'] . '.' . PLATFORM_APPS_DOMAIN)];

        foreach (self::custom_domains($project) as $domain) {
            if (!in_array($domain, $hostnames, true)) {
                $hostnames[] = $domain;
            }
        }

        return $hostnames;
    }

    /**
     * The project's custom domains that may be routed: verified by TXT record
     * (project.verified_domain, db/015) AND seen pointing at the cluster
     * (project.domain_pointed_at, db/019, kept by the recheck_domains task).
     *
     * The DNS half is the gate. A domain that still resolves elsewhere cannot
     * pass HTTP-01, and making it BASE_URL would send every generated link to a
     * host that does not serve the app. It reads the stored fact instead of
     * resolving here, so a resolver hiccup during a deploy can never drop a live
     * domain. Each domain is gated, and gets its own Gateway and certificate
     * (domain_gateway()), on its own, and the {slug} host never is, so one
     * un-pointed domain cannot hold up any other hostname of the app.
     *
     * @return list<string>
     */
    static function custom_domains(array $project): array {
        $domain = strtolower(trim((string) ($project['verified_domain'] ?? '')));

        if ($domain === '' || empty($project['domain_pointed_at'])) {
            return [];
        }

        return [$domain];
    }

    /**
     * The routed custom domains whose certificate has been issued
     * (project.domain_secured_at, db/019, set by recheck_domains once the
     * Certificate is Ready). Only these may become BASE_URL
     * (Trongate_config::base_url): a domain whose certificate is pending, or
     * never comes (CAA record, rate limit), answers with Traefik's default
     * certificate, and must not drag the app's links, including those served on
     * the {slug} host, along with it.
     *
     * @return list<string>
     */
    static function secured_domains(array $project): array {
        return empty($project['domain_secured_at']) ? [] : self::custom_domains($project);
    }

    /**
     * Short hash of a custom domain, for resource names and labels (a domain can
     * be longer than either may be)
     */
    static function domain_hash(string $domain): string {
        return substr(hash('sha256', strtolower($domain)), 0, 12);
    }

    /**
     * Name of the Gateway serving one custom domain: "p{id}-domain-{hash}"
     */
    static function domain_gateway_name(int $project_id, string $domain): string {
        return "p{$project_id}-domain-" . self::domain_hash($domain);
    }

    /**
     * The certificate Secret of a custom-domain Gateway. cert-manager names the
     * Certificate after it too, and does not delete it with the Certificate
     * (no --enable-certificate-owner-ref), so it is deleted with the Gateway
     * (Platform_task::delete_domain_gateway).
     */
    static function domain_tls_secret(string $gateway_name): string {
        return "{$gateway_name}-tls";
    }

    /**
     * Label selector for a project's custom-domain Gateways (to prune the ones it no longer routes)
     */
    static function domain_gateway_selector(int $project_id): string {
        return 'app.kubernetes.io/component=' . self::DOMAIN_COMPONENT . ',' . self::LABEL_PREFIX . '/project-id=' . $project_id;
    }

    /**
     * Label selector for every project's Gateways for one domain, in any
     * namespace (to release the domain from a project that no longer owns it)
     */
    static function domain_hostname_selector(string $domain): string {
        return 'app.kubernetes.io/component=' . self::DOMAIN_COMPONENT . ',' . self::LABEL_PREFIX . '/domain-hash=' . self::domain_hash($domain);
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
     * The app container's requests/limits for a project's resource tier
     */
    static function app_resources(?string $tier): array {
        $resources = Resource_tiers::resources($tier);
        $resources['requests']['ephemeral-storage'] = self::APP_EPHEMERAL_STORAGE['requests'];
        $resources['limits']['ephemeral-storage'] = self::APP_EPHEMERAL_STORAGE['limits'];

        return $resources;
    }

    /**
     * Tenant namespace with quota, limits and network isolation
     */
    static function tenant(int $team_id): array {
        self::_assert_quota_fits_ladder();

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

        // Defaults to the default rung; max is exactly the top rung, so nothing in
        // a tenant namespace can be larger than what the ladder offers
        $default = self::app_resources(Resource_tiers::DEFAULT_TIER);
        $top = Resource_tiers::top();

        $manifests[] = [
            'apiVersion' => 'v1',
            'kind' => 'LimitRange',
            'metadata' => self::_metadata('tenant-limits', $namespace, $labels),
            'spec' => [
                'limits' => [[
                    'type' => 'Container',
                    'default' => $default['limits'],
                    'defaultRequest' => $default['requests'],
                    'max' => ['cpu' => $top['cpu_m'] . 'm', 'memory' => $top['memory_mi'] . 'Mi', 'ephemeral-storage' => '4Gi'],
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

        $manifests[] = self::acme_gateway($namespace, $labels);

        return $manifests;
    }

    /**
     * The namespace's HTTP-only Gateway for HTTP-01 challenges of custom-domain
     * certificates (see domain_gateway()). It has no TLS, so it is valid even
     * while every certificate in the namespace is still pending. Only
     * cert-manager attaches routes to it: the worker's own HTTPRoutes may not
     * (worker-admission-policy.yaml), so apps never serve plain HTTP. Everything
     * else on port 80 still gets the platform Gateway's HTTPS redirect.
     */
    static function acme_gateway(string $namespace, array $labels): array {
        return [
            'apiVersion' => 'gateway.networking.k8s.io/v1',
            'kind' => 'Gateway',
            'metadata' => self::_metadata(self::ACME_GATEWAY, $namespace, $labels),
            'spec' => [
                'gatewayClassName' => PLATFORM_GATEWAY_CLASS,
                'listeners' => [[
                    'name' => 'http',
                    'protocol' => 'HTTP',
                    'port' => self::GATEWAY_HTTP_PORT,
                    'allowedRoutes' => ['namespaces' => ['from' => 'Same']],
                ]],
            ],
        ];
    }

    /**
     * A Gateway in the tenant namespace that serves one custom domain over HTTPS.
     * cert-manager creates its certificate from the annotation.
     *
     * Why not listeners on the platform Gateway: Flux owns that object (infra
     * repo) and would revert the worker's listeners, the worker must not be able
     * to touch the listeners that serve trongate.cloud and *.trongate.dev, and a
     * Gateway holds at most 64 listeners. Why not a ListenerSet: the CRD is
     * installed (Gateway API v1.6.1 standard), but Traefik 3.7 does not list it
     * among its GatewayClass's supported features.
     *
     * Why one Gateway per domain rather than per project or team: a listener
     * whose certificate is not issued yet (or never will be: CAA record, rate
     * limit, DNS moved away) reports ResolvedRefs=False, and its own Gateway
     * confines that to the one domain. The app's HTTPRoute keeps its platform
     * Gateway parent, so the {slug} host never depends on a custom domain.
     *
     * Certificates come from PLATFORM_DOMAIN_ISSUER, an HTTP-01-only ClusterIssuer
     * whose challenge routes attach to acme_gateway() in this namespace.
     * worker-admission-policy.yaml checks the listener: HTTPS, routes from this
     * namespace only, a Secret from this namespace, and a hostname that is never
     * a wildcard, a platform host or under PLATFORM_APPS_DOMAIN.
     */
    static function domain_gateway(array $project, string $domain): array {
        $project_id = (int) $project['id'];
        $name = self::domain_gateway_name($project_id, $domain);
        // The project's ownership labels (project_labels), so destroy_project and
        // the trongate-worker-owned-deletes policy recognise it as the project's
        $metadata = self::_metadata($name, self::namespace_name((int) $project['team_id']), self::project_labels($project_id) + [
            'app.kubernetes.io/component' => self::DOMAIN_COMPONENT,
            self::LABEL_PREFIX . '/domain-hash' => self::domain_hash($domain),
        ]);
        // The only cert-manager annotation: worker-admission-policy.yaml refuses
        // any other (common-name, subject, usages, ...), so the certificate names
        // exactly the listener's hostname
        $metadata['annotations'] = ['cert-manager.io/cluster-issuer' => PLATFORM_DOMAIN_ISSUER];

        return [
            'apiVersion' => 'gateway.networking.k8s.io/v1',
            'kind' => 'Gateway',
            'metadata' => $metadata,
            'spec' => [
                'gatewayClassName' => PLATFORM_GATEWAY_CLASS,
                'listeners' => [[
                    'name' => 'https',
                    'protocol' => 'HTTPS',
                    'port' => self::GATEWAY_HTTPS_PORT,
                    'hostname' => $domain,
                    'tls' => [
                        'mode' => 'Terminate',
                        'certificateRefs' => [['group' => '', 'kind' => 'Secret', 'name' => self::domain_tls_secret($name)]],
                    ],
                    'allowedRoutes' => ['namespaces' => ['from' => 'Same']],
                ]],
            ],
        ];
    }

    /**
     * Everything one project needs inside its tenant namespace, in apply order
     *
     * @param array $project    project row (id, team_id, slug, label, type, verified_domain, domain_pointed_at, domain_secured_at, persistent_paths, always_on, resource_tier)
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

        $labels = self::project_labels($project_id);
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

        // The platform Gateway serves the {slug} host; each routed custom domain
        // has its own Gateway in this namespace (domain_gateway())
        $parent_refs = [[
            'group' => 'gateway.networking.k8s.io',
            'kind' => 'Gateway',
            'name' => PLATFORM_GATEWAY_NAME,
            'namespace' => PLATFORM_GATEWAY_NAMESPACE,
        ]];

        foreach (self::custom_domains($project) as $domain) {
            $manifests[] = self::domain_gateway($project, $domain);
            $parent_refs[] = [
                'group' => 'gateway.networking.k8s.io',
                'kind' => 'Gateway',
                'name' => self::domain_gateway_name($project_id, $domain),
                'sectionName' => 'https',
            ];
        }

        $manifests[] = [
            'apiVersion' => 'gateway.networking.k8s.io/v1',
            'kind' => 'HTTPRoute',
            'metadata' => self::_metadata($name, $namespace, $labels),
            'spec' => [
                'parentRefs' => $parent_refs,
                'hostnames' => self::hostnames($project),
                'rules' => [$rule],
            ],
        ];

        return $manifests;
    }

    /**
     * Ownership labels of every object app() emits for a project
     */
    static function project_labels(int $project_id): array {
        return self::_labels([
            'app.kubernetes.io/name' => self::app_name($project_id),
            self::PROJECT_LABEL => (string) $project_id,
        ]);
    }

    /**
     * Whether a live object is one of this project's: it sits in the team's
     * namespace and carries all of project_labels(). Cleanup checks this before
     * deleting anything, so a wrong name can never remove someone else's object.
     */
    static function owned_by_project(array $object, int $team_id, int $project_id): bool {
        $metadata = $object['metadata'] ?? [];

        if (($metadata['namespace'] ?? null) !== self::namespace_name($team_id)) {
            return false;
        }

        foreach (self::project_labels($project_id) as $key => $value) {
            if (($metadata['labels'][$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * [apiVersion, kind, name] of everything app() may create for a project, in
     * teardown order: traffic first, then workloads, then data access, then config
     *
     * @return list<array{string, string, string}>
     */
    static function project_objects(int $project_id): array {
        $name = self::app_name($project_id);

        return [
            ['gateway.networking.k8s.io/v1', 'HTTPRoute', $name],
            ['traefik.io/v1alpha1', 'Middleware', $name],
            ['v1', 'Service', $name],
            ['apps/v1', 'Deployment', $name],
            ['k8s.mariadb.com/v1alpha1', 'Grant', "{$name}-db-grant"],
            ['k8s.mariadb.com/v1alpha1', 'User', "{$name}-db-user"],
            ['k8s.mariadb.com/v1alpha1', 'Database', "{$name}-db"],
            ['v1', 'PersistentVolumeClaim', "p{$project_id}-files"],
            ['v1', 'Secret', "p{$project_id}-database"],
        ];
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
                        // Read by recheck_domains to see whether a secured custom domain is BASE_URL yet
                        self::LABEL_PREFIX . '/base-url' => $o['base_url'],
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
                        'resources' => self::app_resources($project['resource_tier'] ?? null),
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

    /**
     * The TENANT_QUOTA key a team would exceed if it ran one pod of each of these
     * tiers, or null when they fit.
     *
     * The quota covers the whole team namespace, so every project counts, asleep
     * ones too (they wake up). A rollout also runs the old and the new pod side by
     * side (maxSurge 1), so there must be room for one more pod of the largest
     * tier; one is enough because the worker runs one task at a time. Init
     * containers are smaller than any rung, so they never decide the fit.
     *
     * @param list<string> $tiers one resource tier name per project
     */
    static function quota_shortfall(array $tiers): ?string {
        if (empty($tiers)) {
            return null;
        }

        $pods = array_map(fn(string $tier) => Resource_tiers::resources($tier), $tiers);
        $largest = Resource_tiers::resources(Resource_tiers::largest($tiers));
        $pods[] = $largest;

        foreach (['requests.cpu', 'limits.cpu', 'requests.memory', 'limits.memory'] as $key) {
            [$kind, $resource] = explode('.', $key);
            $parse = $resource === 'cpu' ? self::_millicores(...) : self::_mebibytes(...);
            $total = array_sum(array_map(fn(array $pod) => $parse($pod[$kind][$resource]), $pods));

            if ($total > $parse(self::TENANT_QUOTA[$key])) {
                return $key;
            }
        }

        if (count($pods) > (int) self::TENANT_QUOTA['pods']) {
            return 'pods';
        }

        return null;
    }

    /**
     * A team with a single app on the top rung must be able to redeploy it.
     * Fails loudly when someone grows the ladder past the quota.
     */
    static function _assert_quota_fits_ladder(): void {
        $key = self::quota_shortfall([array_key_last(Resource_tiers::TIERS)]);

        if ($key !== null) {
            throw new LogicException("TENANT_QUOTA {$key} cannot fit two pods of the top resource tier");
        }
    }

    /** "2" or "500m" in millicores */
    static function _millicores(string $quantity): int {
        return str_ends_with($quantity, 'm') ? (int) $quantity : (int) round((float) $quantity * 1000);
    }

    /** "256Mi" or "8Gi" in MiB */
    static function _mebibytes(string $quantity): int {
        return str_ends_with($quantity, 'Gi') ? (int) $quantity * 1024 : (int) $quantity;
    }

    static function _metadata(string $name, string $namespace, array $labels): array {
        return ['name' => $name, 'namespace' => $namespace, 'labels' => $labels];
    }

    static function _labels(array $extra = []): array {
        return ['app.kubernetes.io/managed-by' => self::MANAGED_BY] + $extra;
    }
}
