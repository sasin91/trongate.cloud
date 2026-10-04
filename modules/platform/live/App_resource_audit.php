<?php

require_once __DIR__ . '/../manifests/Manifests.php';
require_once __DIR__ . '/../../services/clients/Kubernetes.php';

/**
 * Read-only audit of per-app objects in tenant namespaces.
 *
 * Lists objects that carry trongate.cloud's ownership labels (managed-by +
 * app id) but no longer belong to a live app in that namespace, e.g.
 * after a destroy_app task failed. It never deletes or repairs anything:
 * re-running the cleanup (the admin MCP's retry_app_cleanup) or removing
 * an object by hand, after a look, is a separate decision.
 *
 * Objects without both labels are not ours to classify and are ignored, as are
 * tenant-wide objects (quota, limits, network policies). Secrets are not
 * checked: the web app cannot read them (deploy/k8s/platform/app-rbac.yaml).
 *
 * Framework-free, like App_state.
 */
class App_resource_audit {

    /** The kinds of Manifests::app_objects() the web app may list */
    const KINDS = [
        ['gateway.networking.k8s.io/v1', 'HTTPRoute'],
        ['traefik.io/v1alpha1', 'Middleware'],
        ['v1', 'Service'],
        ['apps/v1', 'Deployment'],
        ['k8s.mariadb.com/v1alpha1', 'Grant'],
        ['k8s.mariadb.com/v1alpha1', 'User'],
        ['k8s.mariadb.com/v1alpha1', 'Database'],
        ['v1', 'PersistentVolumeClaim'],
    ];

    const TIMEOUT_SECONDS = 10;

    /**
     * Labelled objects whose app is not one of $apps in their namespace.
     * A app deleted a moment ago shows up until its destroy_app has run.
     *
     * @param iterable<array{id: int|string, project_id: int|string}> $apps every app that is not deleted
     * @return list<array{kind: string, namespace: string, name: string, app_id: int}>
     */
    static function orphans(Kubernetes $kube, iterable $apps): array {
        $kube = $kube->with_timeout(self::TIMEOUT_SECONDS);

        $expected = [];
        foreach ($apps as $app) {
            $expected[Manifests::namespace_name((int) $app['project_id'])][(int) $app['id']] = true;
        }

        // One cluster-wide list per kind; the worker can only write in tc-team-* namespaces
        $selector = 'app.kubernetes.io/managed-by=' . Manifests::MANAGED_BY . ',' . Manifests::APP_LABEL;

        $orphans = [];
        foreach (self::KINDS as [$api_version, $kind]) {
            foreach (self::_list($kube, $api_version, $kind, $selector) as $object) {
                $namespace = (string) ($object['metadata']['namespace'] ?? '');
                $app_id = (int) ($object['metadata']['labels'][Manifests::APP_LABEL] ?? 0);

                if (!str_starts_with($namespace, 'tc-team-') || isset($expected[$namespace][$app_id])) {
                    continue;
                }

                $orphans[] = [
                    'kind' => $kind,
                    'namespace' => $namespace,
                    'name' => (string) ($object['metadata']['name'] ?? ''),
                    'app_id' => $app_id,
                ];
            }
        }

        usort($orphans, fn(array $a, array $b) => [$a['namespace'], $a['kind'], $a['name']] <=> [$b['namespace'], $b['kind'], $b['name']]);

        return $orphans;
    }

    /**
     * Objects of a kind in all namespaces; none when the cluster lacks its CRD
     * (then we cannot have created any)
     */
    static function _list(Kubernetes $kube, string $api_version, string $kind, string $selector): array {
        try {
            return $kube->list($api_version, $kind, null, $selector);
        } catch (Client_Error $e) {
            if ($e->getCode() === 404) {
                return [];
            }

            throw $e;
        }
    }
}
