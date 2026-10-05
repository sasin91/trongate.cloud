<?php

require_once __DIR__ . '/Client.php';

/**
 * Kubernetes API client (server-side apply, get, list, delete, rollout wait)
 * https://kubernetes.io/docs/reference/using-api/server-side-apply/
 *
 * Framework-free: loaded by the worker as well as the web app.
 */
class Kubernetes extends Client {

    const IN_CLUSTER_DIR = '/var/run/secrets/kubernetes.io/serviceaccount';
    const IN_CLUSTER_URL = 'https://kubernetes.default.svc';

    /**
     * Resource plural and scope per "apiVersion/Kind"; covers every kind Manifests emits
     */
    const RESOURCES = [
        'v1/Namespace' => ['namespaces', false],
        'v1/Pod' => ['pods', true],
        'v1/Event' => ['events', true],
        'v1/Service' => ['services', true],
        'v1/Secret' => ['secrets', true],
        'v1/ConfigMap' => ['configmaps', true],
        'v1/ServiceAccount' => ['serviceaccounts', true],
        'v1/PersistentVolumeClaim' => ['persistentvolumeclaims', true],
        'v1/ResourceQuota' => ['resourcequotas', true],
        'v1/LimitRange' => ['limitranges', true],
        'apps/v1/Deployment' => ['deployments', true],
        'apps/v1/ReplicaSet' => ['replicasets', true],
        'networking.k8s.io/v1/NetworkPolicy' => ['networkpolicies', true],
        'gateway.networking.k8s.io/v1/HTTPRoute' => ['httproutes', true],
        'gateway.networking.k8s.io/v1/Gateway' => ['gateways', true],
        'traefik.io/v1alpha1/Middleware' => ['middlewares', true],
        'cert-manager.io/v1/Certificate' => ['certificates', true],
        'k8s.mariadb.com/v1alpha1/MariaDB' => ['mariadbs', true],
        'k8s.mariadb.com/v1alpha1/Database' => ['databases', true],
        'k8s.mariadb.com/v1alpha1/User' => ['users', true],
        'k8s.mariadb.com/v1alpha1/Grant' => ['grants', true],
    ];

    /** Overrides Client's 30s request timeout when set (see with_timeout) */
    private ?int $timeout_seconds = null;

    function __construct(string $api_url, private string $token, private ?string $ca_file = null) {
        $this->api_base = rtrim($api_url, '/');
    }

    /**
     * A copy whose requests (and connection attempts) give up after $seconds
     */
    function with_timeout(int $seconds): self {
        $client = clone $this;
        $client->timeout_seconds = $seconds;

        return $client;
    }

    /**
     * Build from KUBE_API_URL / KUBE_TOKEN / KUBE_CA_FILE, falling back to the in-cluster service account
     */
    static function from_env(): self {
        $api_url = getenv('KUBE_API_URL') ?: '';
        $token = getenv('KUBE_TOKEN') ?: '';
        $ca_file = getenv('KUBE_CA_FILE') ?: '';

        if ($api_url === '') {
            $host = getenv('KUBERNETES_SERVICE_HOST');
            $port = getenv('KUBERNETES_SERVICE_PORT') ?: '443';
            $api_url = $host ? 'https://' . (str_contains($host, ':') ? "[{$host}]" : $host) . ':' . $port : self::IN_CLUSTER_URL;
        }

        if ($token === '' && is_readable(self::IN_CLUSTER_DIR . '/token')) {
            $token = trim((string) file_get_contents(self::IN_CLUSTER_DIR . '/token'));
        }

        if ($ca_file === '' && is_readable(self::IN_CLUSTER_DIR . '/ca.crt')) {
            $ca_file = self::IN_CLUSTER_DIR . '/ca.crt';
        }

        if ($token === '') {
            throw new Client_Error('Kubernetes token missing: set KUBE_TOKEN or run in-cluster', 0);
        }

        return new self($api_url, $token, $ca_file ?: null);
    }

    function headers(): array {
        return [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];
    }

    function _curl_options(): array {
        $options = $this->ca_file ? [CURLOPT_CAINFO => $this->ca_file] : [];

        if ($this->timeout_seconds !== null) {
            $options[CURLOPT_TIMEOUT] = $this->timeout_seconds;
            $options[CURLOPT_CONNECTTIMEOUT] = $this->timeout_seconds;
        }

        return $options;
    }

    /**
     * Server-side apply one manifest (force=true); returns the live object
     */
    function apply(array $manifest, string $field_manager = 'trongate-cloud'): array {
        $api_version = $manifest['apiVersion'] ?? '';
        $kind = $manifest['kind'] ?? '';
        $name = $manifest['metadata']['name'] ?? '';
        $namespace = $manifest['metadata']['namespace'] ?? null;

        if ($api_version === '' || $kind === '' || $name === '') {
            throw new InvalidArgumentException('Manifest needs apiVersion, kind and metadata.name');
        }

        $query = http_build_query(['fieldManager' => $field_manager, 'force' => 'true']);

        return $this->request(
            'PATCH',
            $this->path($api_version, $kind, $namespace, $name) . '?' . $query,
            $manifest,
            ['Content-Type: application/apply-patch+yaml']
        );
    }

    /**
     * Apply manifests in the given order
     */
    function apply_all(array $manifests): void {
        foreach ($manifests as $manifest) {
            $this->apply($manifest);
        }
    }

    /**
     * Fetch an object; null when it does not exist
     */
    function get(string $api_version, string $kind, ?string $namespace, string $name): ?array {
        try {
            return $this->request('GET', $this->path($api_version, $kind, $namespace, $name));
        } catch (Client_Error $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * List objects of a kind, optionally filtered by label selector. A null
     * $namespace lists a namespaced kind across all namespaces.
     */
    function list(string $api_version, string $kind, ?string $namespace, string $label_selector = ''): array {
        $path = $this->path($api_version, $kind, $namespace);

        if ($label_selector !== '') {
            $path .= '?' . http_build_query(['labelSelector' => $label_selector]);
        }

        return $this->request('GET', $path)['items'] ?? [];
    }

    /**
     * The last $tail_lines lines of a pod's (only) container log; the log
     * endpoint answers plain text, so this bypasses request()'s JSON decoding.
     * The API server answers "Accept: text/plain" with a 406, so this accepts any type.
     */
    function pod_log(string $namespace, string $name, int $tail_lines = 100): string {
        $url = $this->api_base . $this->path('v1', 'Pod', $namespace, $name) . '/log?'
            . http_build_query(['tailLines' => $tail_lines]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->_merge_headers($this->headers(), ['Accept: */*']),
            CURLOPT_TIMEOUT => 30,
        ] + $this->_curl_options());

        $response = curl_exec($ch);

        if ($response === false) {
            throw new Client_Error('Curl Error: ' . curl_error($ch), 0);
        }

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($http_code >= 400) {
            throw new Client_Error("Client API error ({$http_code}): pod log", $http_code);
        }

        return (string) $response;
    }

    /**
     * Delete an object (background propagation); false when it did not exist
     *
     * With $uid, the API server only deletes the object with that uid (409
     * otherwise), so an object replaced since it was checked survives.
     */
    function delete(string $api_version, string $kind, ?string $namespace, string $name, ?string $uid = null): bool {
        $options = $uid === null ? [] : [
            'apiVersion' => 'v1',
            'kind' => 'DeleteOptions',
            'preconditions' => ['uid' => $uid],
        ];

        try {
            $this->request('DELETE', $this->path($api_version, $kind, $namespace, $name) . '?propagationPolicy=Background', $options);
            return true;
        } catch (Client_Error $e) {
            if ($e->getCode() === 404) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Delete the objects of a kind in a namespace that match a label selector
     * (and field selector); returns how many were deleted.
     *
     * The API server applies the selectors, so this deletes by labels without
     * reading the objects first: the way to delete a Secret only if it carries
     * our labels when the caller cannot read Secrets. The response lists the
     * deleted objects; only their count is returned.
     */
    function delete_collection(string $api_version, string $kind, string $namespace, string $label_selector, string $field_selector = ''): int {
        // Without a selector this would delete every object of the kind
        if ($namespace === '' || trim($label_selector) === '') {
            throw new InvalidArgumentException('delete_collection needs a namespace and a label selector');
        }

        $query = ['labelSelector' => $label_selector, 'propagationPolicy' => 'Background'];

        if ($field_selector !== '') {
            $query['fieldSelector'] = $field_selector;
        }

        $result = $this->request('DELETE', $this->path($api_version, $kind, $namespace) . '?' . http_build_query($query));

        return count($result['items'] ?? []);
    }

    /**
     * Wait until a Deployment has fully rolled out (same checks as `kubectl rollout status`)
     *
     * Returns false on timeout or when the rollout exceeded its progress deadline.
     * $on_progress receives a human-readable status line whenever it changes.
     */
    function wait_for_rollout(string $namespace, string $deployment, int $timeout_seconds = 300, ?callable $on_progress = null): bool {
        $deadline = time() + $timeout_seconds;
        $last_message = '';

        while (true) {
            $object = $this->get('apps/v1', 'Deployment', $namespace, $deployment);
            [$done, $failed, $message] = $this->_rollout_state($object);

            if ($on_progress !== null && $message !== $last_message) {
                $on_progress($message);
                $last_message = $message;
            }

            if ($done) {
                return true;
            }

            if ($failed || time() >= $deadline) {
                return false;
            }

            sleep(2);
        }
    }

    /**
     * @return array{0: bool, 1: bool, 2: string} [done, failed, message]
     */
    function _rollout_state(?array $object): array {
        if ($object === null) {
            return [false, false, 'Waiting for deployment to be created'];
        }

        $generation = $object['metadata']['generation'] ?? 0;
        $status = $object['status'] ?? [];

        if (($status['observedGeneration'] ?? 0) < $generation) {
            return [false, false, 'Waiting for deployment spec update to be observed'];
        }

        foreach ($status['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'Progressing' && ($condition['reason'] ?? '') === 'ProgressDeadlineExceeded') {
                return [false, true, 'Rollout exceeded its progress deadline: ' . ($condition['message'] ?? '')];
            }
        }

        $desired = $object['spec']['replicas'] ?? 1;
        $updated = $status['updatedReplicas'] ?? 0;
        $replicas = $status['replicas'] ?? 0;
        $available = $status['availableReplicas'] ?? 0;

        if ($updated < $desired) {
            return [false, false, "{$updated} of {$desired} new replicas updated"];
        }

        if ($replicas > $updated) {
            return [false, false, ($replicas - $updated) . ' old replicas pending termination'];
        }

        if ($available < $updated) {
            return [false, false, "{$available} of {$updated} updated replicas available"];
        }

        return [true, false, "Rolled out ({$available} of {$desired} replicas available)"];
    }

    /**
     * API path for a kind; omit $name for the collection, and also $namespace
     * for a namespaced kind's collection across all namespaces
     */
    function path(string $api_version, string $kind, ?string $namespace, ?string $name = null): string {
        $key = $api_version . '/' . $kind;

        if (!isset(self::RESOURCES[$key])) {
            throw new InvalidArgumentException("Unknown Kubernetes kind: {$key}");
        }

        [$plural, $namespaced] = self::RESOURCES[$key];
        $path = str_contains($api_version, '/') ? '/apis/' . $api_version : '/api/' . $api_version;

        if ($namespaced) {
            if ($namespace !== null && $namespace !== '') {
                $path .= '/namespaces/' . rawurlencode($namespace);
            } elseif ($name !== null) {
                throw new InvalidArgumentException("{$kind} is namespaced; a namespace is required");
            }
        }

        $path .= '/' . $plural;

        if ($name !== null) {
            $path .= '/' . rawurlencode($name);
        }

        return $path;
    }
}
