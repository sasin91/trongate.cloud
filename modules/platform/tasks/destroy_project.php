<?php

/**
 * Destroy Project Task
 *
 * Deletes a deleted project's namespace. Queued by
 * Platform_actions::queue_project_delete, which only deletes a project that
 * has no apps and no unfinished tasks, and soft-deletes the project row
 * (project.deleted_at) first.
 *
 * Payload: {project_id}. The namespace comes from the project row
 * (project.namespace), never from the id.
 *
 * Deleting a Namespace deletes everything in it, without the per-object
 * ownership checks destroy_app makes. So the namespace is only deleted when
 * all of this holds, and otherwise left in place with the reason logged (the
 * task fails; the admin MCP's retry_project_cleanup runs it again):
 *
 * - the project row exists and is soft-deleted, and has no app that is not
 *   deleted (an app added after queue_project_delete counts too);
 * - every deleted app of the project finished its destroy_app, so their
 *   objects are gone and their custom domains released;
 * - the live Namespace is one the platform created for a tenant
 *   (Manifests::owned_tenant_namespace: managed-by, tenant label, name);
 * - the cluster agrees it holds nothing of an app: no Pod, Deployment,
 *   Service, HTTPRoute, Middleware, PVC, MariaDB CR, or Gateway other than the
 *   namespace's ACME Gateway. What is left is the tenant scaffolding
 *   Manifests::tenant() created (quota, limits, network policies, the ACME
 *   Gateway) plus what Kubernetes and cert-manager keep there.
 *
 * The delete is pinned to the checked Namespace's uid.
 * worker-admission-policy.yaml allows the worker to delete a Namespace only
 * when it has a tenant name and the platform's tenant labels.
 *
 * Kept: the project row (soft-deleted) with its members and deployments, and,
 * as with destroy_app, its apps' databases and files.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $db = $runtime->db();
    $project_id = (int) ($payload['project_id'] ?? 0);

    if ($project_id <= 0) {
        throw new InvalidArgumentException('destroy_project needs project_id');
    }

    $project = Platform_task::row($db, 'SELECT id, namespace, deleted_at FROM project WHERE id = ?', [$project_id]);

    if ($project === null || $project['deleted_at'] === null) {
        throw new RuntimeException("Project #{$project_id} is not a deleted project; refusing to destroy it");
    }

    $sql = <<<SQL
        SELECT
            SUM(a.deleted_at IS NULL) AS live,
            SUM(a.deleted_at IS NOT NULL AND (
                SELECT t.completed_at FROM tasks t
                WHERE t.app_id = a.id AND t.script = 'destroy_app'
                ORDER BY t.id DESC
                LIMIT 1
            ) IS NULL) AS uncleaned
        FROM app a
        WHERE a.project_id = ?
        SQL;
    $apps = Platform_task::row($db, $sql, [$project_id]);

    if ((int) ($apps['live'] ?? 0) > 0) {
        throw new RuntimeException("Project #{$project_id} still has apps; its namespace is left in place");
    }

    if ((int) ($apps['uncleaned'] ?? 0) > 0) {
        throw new RuntimeException("{$apps['uncleaned']} deleted app(s) of project #{$project_id} did not finish destroy_app (see audit_app_resources); its namespace is left in place");
    }

    $namespace = Manifests::namespace_name($project);
    $kube = $runtime->kube();
    $live = $kube->get('v1', 'Namespace', null, $namespace);

    if ($live === null) {
        $log->info("Namespace {$namespace} does not exist (never deployed, or already deleted)");
        return 0;
    }

    if (($live['status']['phase'] ?? '') === 'Terminating') {
        $log->info("Namespace {$namespace} is already being deleted");
        return 0;
    }

    if (!Manifests::owned_tenant_namespace($live)) {
        throw new RuntimeException("Namespace {$namespace} does not carry the platform's tenant labels; left in place");
    }

    // Anything an app runs or is reached through. Kinds whose CRD is not
    // installed cannot have objects.
    $kinds = [
        ['v1', 'Pod'],
        ['apps/v1', 'Deployment'],
        ['v1', 'Service'],
        ['gateway.networking.k8s.io/v1', 'HTTPRoute'],
        ['gateway.networking.k8s.io/v1', 'Gateway'],
        ['traefik.io/v1alpha1', 'Middleware'],
        ['v1', 'PersistentVolumeClaim'],
        ['k8s.mariadb.com/v1alpha1', 'Database'],
        ['k8s.mariadb.com/v1alpha1', 'User'],
        ['k8s.mariadb.com/v1alpha1', 'Grant'],
    ];

    $leftovers = [];

    foreach ($kinds as [$api_version, $kind]) {
        try {
            $objects = $kube->list($api_version, $kind, $namespace);
        } catch (Client_Error $e) {
            if ($e->getCode() === 404) {
                continue;
            }

            throw $e;
        }

        foreach ($objects as $object) {
            $name = (string) ($object['metadata']['name'] ?? '');

            if ($kind === 'Gateway' && $name === Manifests::ACME_GATEWAY) {
                continue;
            }

            $leftovers[] = "{$kind} {$name}";
        }
    }

    if ($leftovers !== []) {
        foreach ($leftovers as $leftover) {
            $log->error("Still in {$namespace}: {$leftover}");
        }

        $log->error("Namespace {$namespace} is left in place: remove these (or re-run their app's cleanup), then retry");
        return 1;
    }

    $uid = $live['metadata']['uid'] ?? null;
    $kube->delete('v1', 'Namespace', null, $namespace, is_string($uid) ? $uid : null);

    $log->info("Deleted namespace {$namespace}; Kubernetes removes its quota, limits, network policies and ACME Gateway with it");

    return 0;
};
