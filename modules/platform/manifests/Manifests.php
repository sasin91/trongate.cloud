<?php

require_once __DIR__ . '/../../../config/platform.php';
require_once __DIR__ . '/Trongate_config.php';
require_once __DIR__ . '/App_env.php';
require_once __DIR__ . '/../mail/Mail_capture.php';
require_once __DIR__ . '/../../../engine/Valkey_sessions.php';

/**
 * Kubernetes manifests for tenants and their apps, as arrays ready for Kubernetes::apply_all().
 *
 * Naming (C2): one namespace per project, stored in project.namespace
 * ("project-{project_id}"),
 * per-app resources "p{app_id}-…", MariaDB database + user "tc_p{app_id}".
 *
 * Framework-free: loaded by the worker.
 */
class Manifests {

    const LABEL_PREFIX = 'platform.example.com';
    const MANAGED_BY = 'trongate-cloud';

    /**
     * Every per-app object carries this label (the app id) next to managed-by.
     * The key dates from when apps were called projects; it stays
     * "project-id" because live objects and worker-admission-policy.yaml use it.
     */
    const APP_LABEL = self::LABEL_PREFIX . '/project-id';

    /**
     * Tenant namespace names, "project-{id}". The worker may only write in these
     * (worker-admission-policy.yaml has the same pattern).
     */
    const NAMESPACE_PATTERN = '/^project-[1-9][0-9]{0,9}$/';

    // The app base image's Caddyfile (docker/app-base) serves ":{$PORT}" from /app/public.
    // We set PORT because restricted pods run as non-root and cannot bind :80.
    const APP_DIR = '/app';
    const APP_PORT = 8080;
    const SERVICE_PORT = 80;
    const RUN_AS_USER = 1000;

    /** How long an app's process gets to finish its current job on a rollout (an LLM call can take 180 s) */
    const PROCESS_GRACE_SECONDS = 210;

    /** An app process's name: a lowercase slug, ending in a letter or digit (it ends a Deployment name) */
    const PROCESS_NAME_PATTERN = '/^[a-z](?:[a-z0-9-]{0,18}[a-z0-9])?$/';

    /** Label naming the process on its Deployment and Pods (Manifests::_process_deployment) */
    const PROCESS_LABEL = 'trongate.cloud/process';

    const MAX_PROCESSES = 5;
    const MAX_PROCESS_REPLICAS = 5;

    // No CPU limit, and customers see no limits (fair use). The memory limit is
    // only a safety net, far above what real apps use (Recruiter: ~60Mi), so a
    // leaking app restarts instead of pushing its node out of memory and taking
    // its neighbours down. The small requests only let the scheduler place pods.
    // ephemeral-storage is capped so one app cannot fill a node's disk.
    const APP_RESOURCES = [
        'requests' => ['cpu' => '50m', 'memory' => '128Mi', 'ephemeral-storage' => '256Mi'],
        'limits' => ['memory' => '2Gi', 'ephemeral-storage' => '1Gi'],
    ];

    // Rollout gate (see _deployment()): a new pod takes no traffic until its
    // home page (or health_path()) answers 2xx/3xx, for up to STARTUP_PROBE_PERIOD *
    // STARTUP_PROBE_FAILURES seconds (about 2 minutes, like the smoke test).
    // The old pod keeps serving meanwhile; a rollout that makes no progress
    // for PROGRESS_DEADLINE_SECONDS fails and the deploy restores the old one.
    const STARTUP_PROBE_PERIOD = 3;
    const STARTUP_PROBE_FAILURES = 40;
    const PROGRESS_DEADLINE_SECONDS = 180;

    /** An app's health check path (health_path()): absolute, no query, no dot or empty segments */
    const HEALTH_PATH_PATTERN = '#^(?!.*//)(?!.*/\.{1,2}(?:/|$))/[A-Za-z0-9._~/-]{0,199}$#';

    const INIT_RESOURCES = [
        'requests' => ['cpu' => '10m', 'memory' => '32Mi', 'ephemeral-storage' => '64Mi'],
        'limits' => ['cpu' => '200m', 'memory' => '128Mi', 'ephemeral-storage' => '256Mi'],
    ];

    // Shared by every app in the project. No CPU or memory quota (see
    // APP_RESOURCES); the quota only bounds disk, pod count and exposure.
    const TENANT_QUOTA = [
        'requests.ephemeral-storage' => '8Gi',
        'limits.ephemeral-storage' => '30Gi',
        'requests.storage' => '200Gi',
        'pods' => '30',
        'persistentvolumeclaims' => '20',
        'services.loadbalancers' => '0',
        'services.nodeports' => '0',
    ];

    // PHP sessions of customer apps live in the shared Valkey (deploy/k8s/cache),
    // so redeploys and scale-to-zero wake-ups keep visitors signed in. Each app
    // signs in as its own ACL user (session_user()) that only reaches keys
    // under its own prefix. The ini file sorts after the image's other conf.d
    // files, so it wins over their session settings.
    const SESSIONS_INI_PATH = '/usr/local/etc/php/conf.d/zz-trongate-cloud-sessions.ini';

    const FILES_SIZE = '10Gi';
    const MAX_USER_CONNECTIONS = 50;
    const SABLIER_SESSION = '15m';
    const SABLIER_PLUGIN = 'sablier';

    // One throwaway BuildKit daemon per build (see buildkitd()), never shared by tenants
    const BUILDKIT_NAMESPACE = 'buildkit';
    const BUILDKIT_IMAGE = 'moby/buildkit:v0.33.1-rootless';
    // SELinux type for the build daemon (see buildkitd()); the worker admission policy pins it
    const BUILDKIT_SELINUX_TYPE = 'container_engine_t';
    const BUILDKIT_PORT = 1234;

    // Egress to the internet excludes cluster, node and link-local (metadata) ranges
    const PRIVATE_V4 = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '169.254.0.0/16'];
    const PRIVATE_V6 = ['fc00::/7', 'fe80::/10'];

    // The mail inbox (deploy/k8s/platform/mail.yaml): its namespace and pod label
    const MAIL_NAMESPACE = 'trongate-cloud';
    const MAIL_APP_LABEL = 'trongate-cloud-mail';
    // On the pods of apps with mail capture off: allow-smtp-submission admits them
    const OWN_SMTP_LABEL = self::LABEL_PREFIX . '/own-smtp';

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

    /**
     * The namespace for a new project. Platform_actions::create_project stores
     * it in project.namespace; everything else reads it from there.
     */
    static function new_namespace(int $project_id): string {
        if ($project_id <= 0) {
            throw new InvalidArgumentException('A project id is required for its namespace');
        }

        return "project-{$project_id}";
    }

    /**
     * The tenant namespace of an app or project row: its project.namespace
     * (app rows carry it through a JOIN on project, see Platform_task::app).
     * Set once at creation (new_namespace) and read from the row from then on.
     *
     * @throws InvalidArgumentException when the row has no valid tenant namespace
     */
    static function namespace_name(array|object $row): string {
        $namespace = (string) (is_array($row) ? ($row['namespace'] ?? '') : ($row->namespace ?? ''));

        if (!self::is_tenant_namespace($namespace)) {
            throw new InvalidArgumentException("Not a tenant namespace: '{$namespace}'");
        }

        return $namespace;
    }

    static function is_tenant_namespace(string $namespace): bool {
        return preg_match(self::NAMESPACE_PATTERN, $namespace) === 1;
    }

    static function app_name(int $app_id): string {
        return "p{$app_id}-app";
    }

    /**
     * The Deployment running one of the app's processes
     */
    static function process_deployment_name(int $app_id, string $process): string {
        return "p{$app_id}-{$process}";
    }

    /**
     * Pods and Deployments of the app's processes (Manifests::_process_deployment)
     */
    static function process_selector(int $app_id): string {
        return self::APP_LABEL . '=' . $app_id . ',app.kubernetes.io/component=process';
    }

    /**
     * The app's processes as Platform_task loaded them into $app['processes']:
     * list of ['name' => ..., 'command' => ..., 'replicas' => ...]
     *
     * @return list<array{name: string, command: string, replicas: int}>
     */
    static function processes(array $app): array {
        return array_values(array_map(fn(array $p) => [
            'name' => (string) $p['name'],
            'command' => trim((string) $p['command']),
            'replicas' => max(1, min(self::MAX_PROCESS_REPLICAS, (int) ($p['replicas'] ?? 1))),
        ], (array) ($app['processes'] ?? [])));
    }

    static function database_name(int $app_id): string {
        return "tc_p{$app_id}";
    }

    /**
     * Hostnames routed to an app: "{slug}.PLATFORM_APPS_DOMAIN" (unless its
     * staging app took it, serves_slug_host()) plus its routed custom
     * domains (custom_domains())
     */
    static function hostnames(array $app): array {
        $hostnames = [];

        if (self::serves_slug_host($app)) {
            $hostnames[] = strtolower($app['slug'] . '.' . PLATFORM_APPS_DOMAIN);
        }

        foreach (self::custom_domains($app) as $domain) {
            if (!in_array($domain, $hostnames, true)) {
                $hostnames[] = $domain;
            }
        }

        return $hostnames;
    }

    /**
     * The app's custom domains that may be routed: verified by TXT record
     * (app.verified_domain) AND seen pointing at the cluster
     * (app.domain_pointed_at, kept by the recheck_domains task).
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
    static function custom_domains(array $app): array {
        $domain = strtolower(trim((string) ($app['verified_domain'] ?? '')));

        if ($domain === '') {
            return [];
        }

        // A production app without the {slug} host has nothing else to answer
        // on, so its domain stays routed even while its DNS checks fail
        if (empty($app['domain_pointed_at']) && self::serves_slug_host($app)) {
            return [];
        }

        return [$domain];
    }

    /**
     * Whether the app answers on {slug}.PLATFORM_APPS_DOMAIN: every app but a
     * production app whose staging app took it (app.serves_slug_host,
     * tasks/split_staging.php)
     */
    static function serves_slug_host(array $app): bool {
        return (int) ($app['serves_slug_host'] ?? 1) === 1;
    }

    /**
     * The routed custom domains whose certificate has been issued
     * (app.domain_secured_at, set by recheck_domains once the
     * Certificate is Ready). Only these may become BASE_URL
     * (Trongate_config::base_url): a domain whose certificate is pending, or
     * never comes (CAA record, rate limit), answers with Traefik's default
     * certificate, and must not drag the app's links, including those served on
     * the {slug} host, along with it.
     *
     * @return list<string>
     */
    static function secured_domains(array $app): array {
        return empty($app['domain_secured_at']) ? [] : self::custom_domains($app);
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
    static function domain_gateway_name(int $app_id, string $domain): string {
        return "p{$app_id}-domain-" . self::domain_hash($domain);
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
     * Label selector for an app's custom-domain Gateways (to prune the ones it no longer routes)
     */
    static function domain_gateway_selector(int $app_id): string {
        return 'app.kubernetes.io/component=' . self::DOMAIN_COMPONENT . ',' . self::APP_LABEL . '=' . $app_id;
    }

    /**
     * Label selector for every app's Gateways for one domain, in any
     * namespace (to release the domain from an app that no longer owns it)
     */
    static function domain_hostname_selector(string $domain): string {
        return 'app.kubernetes.io/component=' . self::DOMAIN_COMPONENT . ',' . self::LABEL_PREFIX . '/domain-hash=' . self::domain_hash($domain);
    }

    /**
     * Stable per-app MariaDB password derived from APP_KEY (rotating APP_KEY rotates it on next deploy)
     */
    static function database_password(int $app_id): string {
        $key = defined('APP_KEY') ? (string) constant('APP_KEY') : (string) getenv('APP_KEY');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is required to derive database passwords');
        }

        return substr(hash_hmac('sha256', 'database:' . self::database_name($app_id), $key), 0, 48);
    }

    /**
     * The app's Valkey ACL user; its session keys start with "{user}:" (Valkey_sessions)
     */
    static function session_user(int $app_id): string {
        return "tc_p{$app_id}";
    }

    /**
     * php.ini that keeps the app's sessions in Valkey (phpredis session handler)
     *
     * @param array{host: string, port: int, password: string} $sessions
     */
    static function sessions_ini(int $app_id, array $sessions): string {
        $save_path = Valkey_sessions::save_path($sessions['host'], (int) $sessions['port'], self::session_user($app_id), $sessions['password']);

        return "; Managed by trongate.cloud: PHP sessions in the shared Valkey, so redeploys\n"
            . "; and wake-ups from sleep keep visitors signed in.\n"
            . "session.save_handler = redis\n"
            . "session.save_path = \"{$save_path}\"\n"
            . 'session.gc_maxlifetime = ' . Valkey_sessions::LIFETIME_SECONDS . "\n";
    }

    /**
     * Normalised persistent paths (relative to the app dir); rejects anything that escapes it
     *
     * @return list<string>
     */
    static function persistent_paths(array $app): array {
        $paths = $app['persistent_paths'] ?? [];

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
     * The path the rollout gate and the smoke test request (app.health_path):
     * the home page unless the app names another, e.g. /health for an API
     * whose home page is a 404. Anything not HEALTH_PATH_PATTERN is the home page.
     */
    static function health_path(array $app): string {
        $path = trim((string) ($app['health_path'] ?? ''));

        return preg_match(self::HEALTH_PATH_PATTERN, $path) ? $path : '/';
    }

    /**
     * Tenant namespace with quota, limits and network isolation
     *
     * @param string $namespace project.namespace (Manifests::namespace_name)
     */
    static function tenant(string $namespace): array {
        if (!self::is_tenant_namespace($namespace)) {
            throw new InvalidArgumentException("Not a tenant namespace: '{$namespace}'");
        }

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

        // Any container without its own resources gets the app's; only disk has a max

        $manifests[] = [
            'apiVersion' => 'v1',
            'kind' => 'LimitRange',
            'metadata' => self::_metadata('tenant-limits', $namespace, $labels),
            'spec' => [
                'limits' => [[
                    'type' => 'Container',
                    'default' => self::APP_RESOURCES['limits'],
                    'defaultRequest' => self::APP_RESOURCES['requests'],
                    'max' => ['ephemeral-storage' => '4Gi'],
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

        // The apps of one project may call each other (http://p{id}-app), on
        // the app port only. A podSelector without a namespaceSelector matches
        // pods in this namespace alone, so other projects stay unreachable.
        // Both directions: default-deny covers egress too, and allow-internet
        // leaves out the cluster's private ranges.
        $same_namespace = [['podSelector' => (object) []]];

        $manifests[] = $network_policy('allow-same-project', [
            'policyTypes' => ['Ingress', 'Egress'],
            'ingress' => [['from' => $same_namespace, 'ports' => [$tcp(self::APP_PORT)]]],
            'egress' => [['to' => $same_namespace, 'ports' => [$tcp(self::APP_PORT)]]],
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

        // The platform's mail inbox (Mail_capture), where apps with mail capture on send
        $manifests[] = $network_policy('allow-platform-mail', [
            'policyTypes' => ['Egress'],
            'egress' => [[
                'to' => [[
                    'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => self::MAIL_NAMESPACE]],
                    'podSelector' => ['matchLabels' => ['app.kubernetes.io/name' => self::MAIL_APP_LABEL]],
                ]],
                'ports' => [$tcp(PLATFORM_MAIL_PORT)],
            ]],
        ]);

        $internet = [
            ['ipBlock' => ['cidr' => '0.0.0.0/0', 'except' => self::PRIVATE_V4]],
            ['ipBlock' => ['cidr' => '::/0', 'except' => self::PRIVATE_V6]],
        ];

        $manifests[] = $network_policy('allow-internet', [
            'policyTypes' => ['Egress'],
            'egress' => [['to' => $internet, 'ports' => [$tcp(80), $tcp(443)]]],
        ]);

        // SMTP submission (465, 587; never 25) only for the pods of apps that
        // turned mail capture off and send through their own provider
        $manifests[] = [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => self::_metadata('allow-smtp-submission', $namespace, $labels),
            'spec' => [
                'podSelector' => ['matchLabels' => [self::OWN_SMTP_LABEL => 'true']],
                'policyTypes' => ['Egress'],
                'egress' => [['to' => $internet, 'ports' => [$tcp(465), $tcp(587)]]],
            ],
        ];

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
     * Why one Gateway per domain rather than per app or project: a listener
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
    static function domain_gateway(array $app, string $domain): array {
        $app_id = (int) $app['id'];
        $name = self::domain_gateway_name($app_id, $domain);
        // The app's ownership labels (app_labels), so destroy_app and
        // the trongate-worker-owned-deletes policy recognise it as the app's
        $metadata = self::_metadata($name, self::namespace_name($app), self::app_labels($app_id) + [
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
     * Everything one app needs inside its tenant namespace, in apply order
     *
     * @param array $app        app row (id, namespace, slug, label, type, verified_domain, domain_pointed_at, domain_secured_at, persistent_paths, always_on, trongate_env)
     * @param array $deployment deployment row (id, image)
     * @param array $database   database_server row (host, port, operator_name, operator_namespace); optional 'password'
     */
    /**
     * @param array{host: string, port: int, password: string}|null $sessions Valkey
     *        for PHP sessions (Platform_task::ensure_session_user); null keeps
     *        PHP's file sessions inside the pod
     * @param array<string, string> $env the app's environment variables (App_env),
     *        written to the p{id}-env Secret and loaded with envFrom
     */
    static function app(array $app, array $deployment, array $database, ?array $sessions = null, array $env = []): array {
        $app_id = (int) $app['id'];
        $namespace = self::namespace_name($app);
        $name = self::app_name($app_id);
        $secret_name = "p{$app_id}-database";
        $pvc_name = "p{$app_id}-files";
        $db_name = self::database_name($app_id);
        $always_on = !empty($app['always_on']);
        $paths = self::persistent_paths($app);
        $image = (string) ($deployment['image'] ?? '');

        if ($image === '') {
            throw new InvalidArgumentException('Deployment has no image');
        }

        $labels = self::app_labels($app_id);
        $selector = ['app.kubernetes.io/name' => $name];

        $password = (string) ($database['password'] ?? self::database_password($app_id));
        $sessions_ini = $sessions !== null ? self::sessions_ini($app_id, $sessions) : '';

        if (!isset($deployment['config_files'])) {
            throw new InvalidArgumentException('Deployment has no config files');
        }

        // The app's own config/*.php with the platform's values set, mounted over the image's
        $config_files = Trongate_config::config_files((array) json_decode((string) $deployment['config_files'], true), $app, [
            'host' => (string) $database['host'],
            'port' => (string) ($database['port'] ?? 3306),
            'user' => $db_name,
            'password' => $password,
            'database' => $db_name,
        ]);

        $base_url = Trongate_config::base_url($app);
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
            'stringData' => $config_files
                + ['password' => $password]
                + ($sessions_ini !== '' ? ['sessions.ini' => $sessions_ini] : []),
        ];

        // Always written, so removing the last variable empties it on the next
        // deploy. 'data', not 'stringData': server-side apply then owns each key,
        // so a variable left out of the next apply is removed from the Secret.
        $manifests[] = [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => self::_metadata(self::env_secret_name($app_id), $namespace, $labels),
            'type' => 'Opaque',
            'data' => (object) array_map('base64_encode', $env),
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

        $manifests[] = $app_deployment = self::_deployment($app, $deployment, [
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
            'config_files' => array_keys($config_files),
            'sessions' => $sessions_ini !== '',
            'env_secret_name' => self::env_secret_name($app_id),
            // Changed variables roll the pods: envFrom is only read at container start
            'config_hash' => substr(hash('sha256', json_encode($config_files) . $sessions_ini . json_encode($paths) . json_encode($env)), 0, 16),
        ]);

        foreach (self::processes($app) as $process) {
            $manifests[] = self::_process_deployment($app_deployment, $app_id, $process);
        }

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
                            'group' => self::sablier_group($app),
                            'sessionDuration' => self::SABLIER_SESSION,
                            'dynamic' => [
                                'displayName' => (string) ($app['label'] ?? $app['slug']),
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

        foreach (self::custom_domains($app) as $domain) {
            $manifests[] = self::domain_gateway($app, $domain);
            $parent_refs[] = [
                'group' => 'gateway.networking.k8s.io',
                'kind' => 'Gateway',
                'name' => self::domain_gateway_name($app_id, $domain),
                'sectionName' => 'https',
            ];
        }

        $manifests[] = [
            'apiVersion' => 'gateway.networking.k8s.io/v1',
            'kind' => 'HTTPRoute',
            'metadata' => self::_metadata($name, $namespace, $labels),
            'spec' => [
                'parentRefs' => $parent_refs,
                'hostnames' => self::hostnames($app),
                'rules' => [$rule],
            ],
        ];

        return $manifests;
    }

    /**
     * Ownership labels of every object app() emits for an app
     */
    static function app_labels(int $app_id): array {
        return self::_labels([
            'app.kubernetes.io/name' => self::app_name($app_id),
            self::APP_LABEL => (string) $app_id,
        ]);
    }

    /**
     * Whether a live object is one of this app's: it sits in its project's
     * namespace and carries all of app_labels(). Cleanup checks this before
     * deleting anything, so a wrong name can never remove someone else's object.
     */
    static function owned_by_app(array $object, string $namespace, int $app_id): bool {
        $metadata = $object['metadata'] ?? [];

        if (!self::is_tenant_namespace($namespace) || ($metadata['namespace'] ?? null) !== $namespace) {
            return false;
        }

        foreach (self::app_labels($app_id) as $key => $value) {
            if (($metadata['labels'][$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a live Namespace is a tenant namespace the platform created
     * (tenant()): a tenant name, managed-by and its own tenant label.
     * destroy_project checks this before deleting one.
     */
    static function owned_tenant_namespace(array $object): bool {
        $name = (string) ($object['metadata']['name'] ?? '');
        $labels = $object['metadata']['labels'] ?? [];

        return self::is_tenant_namespace($name)
            && ($labels['app.kubernetes.io/managed-by'] ?? null) === self::MANAGED_BY
            && ($labels[self::LABEL_PREFIX . '/managed'] ?? null) === 'tenant'
            && ($labels[self::LABEL_PREFIX . '/tenant'] ?? null) === $name;
    }

    /**
     * [apiVersion, kind, name] of everything app() may create for an app, in
     * teardown order: traffic first, then workloads, then data access, then config
     *
     * @return list<array{string, string, string}>
     */
    static function app_objects(int $app_id): array {
        $name = self::app_name($app_id);

        return [
            ['gateway.networking.k8s.io/v1', 'HTTPRoute', $name],
            ['traefik.io/v1alpha1', 'Middleware', $name],
            ['v1', 'Service', $name],
            ['apps/v1', 'Deployment', $name],
            ['k8s.mariadb.com/v1alpha1', 'Grant', "{$name}-db-grant"],
            ['k8s.mariadb.com/v1alpha1', 'User', "{$name}-db-user"],
            ['k8s.mariadb.com/v1alpha1', 'Database', "{$name}-db"],
            ['v1', 'PersistentVolumeClaim', "p{$app_id}-files"],
            ['v1', 'Secret', "p{$app_id}-database"],
            ['v1', 'Secret', self::env_secret_name($app_id)],
        ];
    }

    /**
     * The Secret holding an app's own environment variables
     */
    static function env_secret_name(int $app_id): string {
        return "p{$app_id}-env";
    }

    /**
     * Sablier group shared by the Deployment labels and the Middleware (label-value safe, cluster unique)
     */
    static function sablier_group(array $app): string {
        return self::namespace_name($app) . '-p' . (int) $app['id'];
    }

    /**
     * A rootless BuildKit daemon Pod for one build: a deployment's ($kind 'd',
     * $id the deployment id) or the platform's own ('p', the build_platform
     * task id). The worker
     * creates it, builds against tcp://{podIP}:BUILDKIT_PORT and deletes it, so
     * untrusted build steps never share a daemon, PID namespace, local cache or
     * forwarded credentials with another tenant's build. The registry cache
     * (--import-cache/--export-cache) is the only cache that outlives it.
     *
     * Rootless BuildKit needs Unconfined seccomp/AppArmor (the buildkit namespace
     * is "privileged" for that); --oci-worker-no-process-sandbox is safe here
     * because nothing else runs in the daemon.
     *
     * The nodes run SELinux enforcing (openSUSE Leap Micro). Under the default
     * container_t, runc inside the pod may not mount a new devpts, so every
     * container BuildKit starts (each RUN step) dies with
     * "error mounting devpts ... permission denied" and buildctl only says
     * "exit code: 1". container_engine_t is container-selinux's type for a
     * container engine inside a container: it allows those mounts and is
     * still confined, unlike spc_t.
     */
    static function buildkitd(int $id, int $timeout_seconds, string $kind = 'd'): array {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => self::_metadata(self::buildkitd_name($id, $kind), self::BUILDKIT_NAMESPACE, self::_labels([
                // buildkit/buildkitd.yaml's NetworkPolicy selects this label
                'app.kubernetes.io/name' => 'buildkitd',
                self::LABEL_PREFIX . ($kind === 'p' ? '/platform-build-task-id' : '/deployment-id') => (string) $id,
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
                        'seLinuxOptions' => ['type' => self::BUILDKIT_SELINUX_TYPE],
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

    /**
     * buildkitd-d{deployment id}, or buildkitd-p{task id} for a platform build;
     * worker-admission-policy.yaml allows exactly these two prefixes.
     */
    static function buildkitd_name(int $id, string $kind = 'd'): string {
        if (!in_array($kind, ['d', 'p'], true)) {
            throw new InvalidArgumentException("Unknown build kind: {$kind}");
        }

        return "buildkitd-{$kind}{$id}";
    }

    static function _deployment(array $app, array $deployment, array $o): array {
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

        $volumes = [
            ['name' => 'tmp', 'emptyDir' => ['sizeLimit' => '256Mi']],
            // The config files the worker edited (Trongate_config::config_files)
            ['name' => 'config-files', 'secret' => [
                'secretName' => $o['secret_name'],
                'items' => array_map(fn(string $file) => ['key' => $file, 'path' => $file], $o['config_files']),
            ]],
        ];

        $init_containers = [];
        $mounts = [['name' => 'tmp', 'mountPath' => '/tmp']];

        foreach ($o['config_files'] as $file) {
            $mounts[] = ['name' => 'config-files', 'mountPath' => "{$app_dir}/config/{$file}", 'subPath' => $file, 'readOnly' => true];
        }

        if ($o['sessions']) {
            // Images built before the redis extension was added (a rollback)
            // cannot load the redis save handler; they get an empty ini and
            // keep file sessions instead of failing every session_start().
            $volumes[] = ['name' => 'config', 'emptyDir' => ['sizeLimit' => '1Mi']];
            $volumes[] = ['name' => 'sessions', 'secret' => [
                'secretName' => $o['secret_name'],
                'items' => [['key' => 'sessions.ini', 'path' => 'sessions.ini']],
            ]];
            $init_containers[] = [
                'name' => 'config',
                'image' => $o['image'],
                'command' => ['sh', '-c', "set -eu\n"
                    . "if php -r 'exit(extension_loaded(\"redis\") ? 0 : 1);' 2>/dev/null; then\n"
                    . "  cp /sessions/sessions.ini /work/sessions.ini\n"
                    . "else\n"
                    . "  echo 'No redis extension in this image; PHP sessions stay in the pod' >&2\n"
                    . "  : > /work/sessions.ini\n"
                    . "fi\n"],
                'volumeMounts' => [
                    ['name' => 'config', 'mountPath' => '/work'],
                    ['name' => 'sessions', 'mountPath' => '/sessions', 'readOnly' => true],
                ],
                'securityContext' => $container_security,
                'resources' => self::INIT_RESOURCES,
            ];
            $mounts[] = ['name' => 'config', 'mountPath' => self::SESSIONS_INI_PATH, 'subPath' => 'sessions.ini', 'readOnly' => true];
        }

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
            $labels['sablier.group'] = self::sablier_group($app);
        }

        $spec = [
            'revisionHistoryLimit' => 3,
            // Start the new pod next to the old one and only remove the old
            // one once the new one is ready (the startup probe below)
            'strategy' => [
                'type' => 'RollingUpdate',
                'rollingUpdate' => ['maxSurge' => 1, 'maxUnavailable' => 0],
            ],
            'progressDeadlineSeconds' => self::PROGRESS_DEADLINE_SECONDS,
            'selector' => ['matchLabels' => $o['selector']],
            'template' => [
                'metadata' => [
                    'labels' => $o['labels'] + (Mail_capture::enabled($app) ? [] : [self::OWN_SMTP_LABEL => 'true']),
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
                        // The customer's variables; 'env' below wins on a name
                        // clash, so the platform's own stay put (App_env::RESERVED)
                        'envFrom' => [['secretRef' => ['name' => $o['env_secret_name']]]],
                        'env' => [
                            ['name' => 'PORT', 'value' => (string) self::APP_PORT],
                            // Caddy needs writable config/data dirs; the image's defaults are root-owned
                            ['name' => 'XDG_CONFIG_HOME', 'value' => '/tmp/caddy/config'],
                            ['name' => 'XDG_DATA_HOME', 'value' => '/tmp/caddy/data'],
                        ],
                        'volumeMounts' => $mounts,
                        // The gate: until the home page (or the app's health
                        // check path) answers (any 2xx or 3xx), the pod is not
                        // ready, takes no traffic and the old pod is not
                        // removed. A startup probe runs only until it first
                        // passes, so the app is not polled for as long as it
                        // runs; the TCP readiness probe takes over after it.
                        'startupProbe' => [
                            'httpGet' => [
                                'path' => self::health_path($app),
                                'port' => 'http',
                                'httpHeaders' => [['name' => 'Host', 'value' => (string) parse_url($o['base_url'], PHP_URL_HOST)]],
                            ],
                            'periodSeconds' => self::STARTUP_PROBE_PERIOD,
                            'timeoutSeconds' => 10,
                            'failureThreshold' => self::STARTUP_PROBE_FAILURES,
                        ],
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

    /**
     * One of the app's processes: the app Deployment's pod (same image,
     * config files, variables, persistent files and database) running the
     * process's command instead of FrankenPHP, e.g. a queue worker's
     * `php bin/queue.php work --time-limit=3600`. Never scaled to zero by
     * Sablier, and not behind the Service (its name label differs from the
     * app's).
     *
     * The command is exec'd, so it gets SIGTERM on a rollout and has
     * PROCESS_GRACE_SECONDS to finish what it is doing. When it exits, the
     * kubelet starts it again; a worker that stops on a time limit is how it
     * keeps memory and code fresh. Its replicas run side by side (a queue
     * hands each job to one of them); on a rollout the old ones stop before
     * the new ones start.
     *
     * @param array{name: string, command: string, replicas: int} $process
     */
    static function _process_deployment(array $app_deployment, int $app_id, array $process): array {
        $name = self::process_deployment_name($app_id, $process['name']);
        $deployment = $app_deployment;
        $relabel = function (array $labels) use ($name, $process): array {
            unset($labels['sablier.enable'], $labels['sablier.group']);
            return ['app.kubernetes.io/name' => $name, 'app.kubernetes.io/component' => 'process', self::PROCESS_LABEL => $process['name']] + $labels;
        };

        $deployment['metadata']['name'] = $name;
        $deployment['metadata']['labels'] = $relabel($deployment['metadata']['labels']);
        $deployment['spec']['replicas'] = $process['replicas'];
        $deployment['spec']['selector'] = ['matchLabels' => ['app.kubernetes.io/name' => $name]];
        $deployment['spec']['strategy'] = ['type' => 'Recreate'];
        $deployment['spec']['template']['metadata']['labels'] = $relabel($deployment['spec']['template']['metadata']['labels']);
        $deployment['spec']['template']['spec']['terminationGracePeriodSeconds'] = self::PROCESS_GRACE_SECONDS;

        // Same container name as the app's, so App_logs reads them all
        $container = &$deployment['spec']['template']['spec']['containers'][0];
        $container['command'] = ['sh', '-c', 'exec ' . $process['command']];
        unset($container['ports'], $container['startupProbe'], $container['readinessProbe']);
        unset($container['securityContext']['capabilities']['add']);
        unset($container);

        return $deployment;
    }

    static function _metadata(string $name, string $namespace, array $labels): array {
        return ['name' => $name, 'namespace' => $namespace, 'labels' => $labels];
    }

    static function _labels(array $extra = []): array {
        return ['app.kubernetes.io/managed-by' => self::MANAGED_BY] + $extra;
    }
}
