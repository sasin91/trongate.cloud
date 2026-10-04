<?php

/**
 * Helpers shared by the platform task scripts (deploy, rollback, destroy_project).
 *
 * Lives in tasks/lib/ so the Runtime can never run it as a task
 * (it only includes tasks/{script}.php). Framework-free.
 */
class Platform_task {

    /** How long a rollout may take before the deployment fails */
    const ROLLOUT_TIMEOUT_SECONDS = 300;

    /** How long a per-build BuildKit daemon may take to become ready */
    const BUILDKITD_READY_SECONDS = 180;

    /**
     * Fetch one row with a prepared statement
     */
    static function row(mysqli $db, string $sql, string $types = '', mixed ...$params): ?array {
        $stmt = $db->prepare($sql);

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * The project row; fails unless it belongs to the team and is not deleted
     */
    static function project(mysqli $db, int $team_id, int $project_id): array {
        $sql = <<<SQL
            SELECT *
            FROM project
            WHERE id = ? AND team_id = ? AND deleted_at IS NULL
            SQL;

        $project = self::row($db, $sql, 'ii', $project_id, $team_id);

        if ($project === null) {
            throw new RuntimeException("Project #{$project_id} not found for team #{$team_id} (or deleted)");
        }

        return $project;
    }

    /**
     * The deployment row; fails unless it belongs to the project
     */
    static function deployment(mysqli $db, int $project_id, int $deployment_id): array {
        $sql = <<<SQL
            SELECT *
            FROM deployment
            WHERE id = ? AND project_id = ?
            SQL;

        $deployment = self::row($db, $sql, 'ii', $deployment_id, $project_id);

        if ($deployment === null) {
            throw new RuntimeException("Deployment #{$deployment_id} not found for project #{$project_id}");
        }

        return $deployment;
    }

    /**
     * The project's database server. Places the project on the active server
     * with the fewest projects (and spare capacity) on first deploy.
     */
    static function database_server(mysqli $db, array &$project, Task_log $log): array {
        if (!empty($project['database_server_id'])) {
            $server = self::row($db, 'SELECT * FROM database_server WHERE id = ?', 'i', (int) $project['database_server_id']);

            if ($server === null) {
                throw new RuntimeException("Database server #{$project['database_server_id']} no longer exists");
            }

            return $server;
        }

        $sql = <<<SQL
            SELECT ds.*, COUNT(p.id) AS project_count
            FROM database_server ds
            LEFT JOIN project p ON p.database_server_id = ds.id
            WHERE ds.active = 1
            GROUP BY ds.id
            HAVING project_count < ds.capacity
            ORDER BY project_count, ds.id
            LIMIT 1
            SQL;

        $server = self::row($db, $sql);

        if ($server === null) {
            throw new RuntimeException('No active database server has spare capacity');
        }

        // Only claim when still unplaced, so two concurrent deploys agree on one server
        $stmt = $db->prepare('UPDATE project SET database_server_id = ? WHERE id = ? AND database_server_id IS NULL');
        $server_id = (int) $server['id'];
        $project_id = (int) $project['id'];
        $stmt->bind_param('ii', $server_id, $project_id);
        $stmt->execute();
        $claimed = $stmt->affected_rows > 0;
        $stmt->close();

        if (!$claimed) {
            $fresh = self::row($db, 'SELECT database_server_id FROM project WHERE id = ?', 'i', $project_id);
            $project['database_server_id'] = $fresh['database_server_id'] ?? null;

            return self::database_server($db, $project, $log);
        }

        $project['database_server_id'] = $server_id;
        $log->info("Placed database on {$server['label']}");

        return $server;
    }

    /**
     * Apply the tenant namespace and the project's app manifests
     */
    static function apply(Runtime $runtime, Task_log $log, array $project, array $deployment, array $database): void {
        $kube = $runtime->kube();
        $team_id = (int) $project['team_id'];

        $log->info('Applying tenant namespace ' . Manifests::namespace_name($team_id));
        $kube->apply_all(Manifests::tenant($team_id));

        $manifests = Manifests::app($project, $deployment, $database);

        foreach ($manifests as $manifest) {
            // Names and kinds only: Secrets carry the database password
            $log->info("Applying {$manifest['kind']} {$manifest['metadata']['name']}");
            $kube->apply($manifest);
        }
    }

    /**
     * Wait for the app Deployment to roll out, logging progress; on failure log why pods are unhappy
     */
    static function wait_for_rollout(Runtime $runtime, Task_log $log, array $project): bool {
        $kube = $runtime->kube();
        $namespace = Manifests::namespace_name((int) $project['team_id']);
        $name = Manifests::app_name((int) $project['id']);

        $done = $kube->wait_for_rollout($namespace, $name, self::ROLLOUT_TIMEOUT_SECONDS, function (string $message) use ($log) {
            $log->info($message);
        });

        if ($done) {
            $live = $kube->get('apps/v1', 'Deployment', $namespace, $name);

            if (($live['spec']['replicas'] ?? 1) === 0) {
                $log->info('App is scaled to zero; it starts on the first request');
            }

            return true;
        }

        $log->error('Rollout did not complete within ' . self::ROLLOUT_TIMEOUT_SECONDS . 's');
        self::log_pod_problems($runtime, $log, $namespace, $name);

        return false;
    }

    /**
     * Log waiting/terminated reasons of the app's pods (e.g. CrashLoopBackOff, ImagePullBackOff)
     */
    static function log_pod_problems(Runtime $runtime, Task_log $log, string $namespace, string $name): void {
        try {
            $pods = $runtime->kube()->list('v1', 'Pod', $namespace, 'app.kubernetes.io/name=' . $name);
        } catch (Throwable $e) {
            $log->warn('Could not list pods: ' . $e->getMessage());
            return;
        }

        foreach ($pods as $pod) {
            $pod_name = $pod['metadata']['name'] ?? '?';
            $statuses = array_merge(
                $pod['status']['initContainerStatuses'] ?? [],
                $pod['status']['containerStatuses'] ?? []
            );

            foreach ($statuses as $status) {
                $state = $status['state'] ?? [];
                $detail = $state['waiting'] ?? $state['terminated'] ?? null;

                if ($detail === null || ($detail['reason'] ?? '') === 'Completed') {
                    continue;
                }

                $log->error(sprintf(
                    '%s/%s: %s %s',
                    $pod_name,
                    $status['name'] ?? '?',
                    $detail['reason'] ?? 'Unknown',
                    $detail['message'] ?? ''
                ));
            }

            foreach ($pod['status']['conditions'] ?? [] as $condition) {
                if (($condition['type'] ?? '') === 'PodScheduled' && ($condition['status'] ?? '') === 'False') {
                    $log->error("{$pod_name}: not scheduled: " . ($condition['message'] ?? ''));
                }
            }
        }
    }

    /**
     * Delete one of a project's objects, but only after checking that it carries
     * the project's ownership labels (Manifests::owned_by_project). The delete is
     * pinned to the checked object's uid, so a replacement is never removed.
     *
     * Secrets are write-only for the worker (worker-rbac.yaml) and cannot be
     * read first. They are deleted as a collection filtered by name and by the
     * same labels, so the API server does the label check: a Secret under that
     * name without the project's labels matches nothing and stays. The
     * admission policy trongate-worker-owned-deletes is a backstop for every
     * kind.
     *
     * @return bool false when the object was already gone (for a Secret: or is not the project's)
     * @throws RuntimeException when the object exists but is not the project's
     */
    static function delete_owned(Kubernetes $kube, string $api_version, string $kind, int $team_id, int $project_id, string $name): bool {
        $namespace = Manifests::namespace_name($team_id);

        if ($api_version === 'v1' && $kind === 'Secret') {
            $labels = array_map(fn(string $key, string $value) => "{$key}={$value}", array_keys(Manifests::project_labels($project_id)), Manifests::project_labels($project_id));

            return $kube->delete_collection($api_version, $kind, $namespace, implode(',', $labels), 'metadata.name=' . $name) > 0;
        }

        $object = $kube->get($api_version, $kind, $namespace, $name);

        if ($object === null) {
            return false;
        }

        if (!Manifests::owned_by_project($object, $team_id, $project_id)) {
            throw new RuntimeException("{$kind} {$name} does not carry project #{$project_id}'s labels; left in place");
        }

        $uid = $object['metadata']['uid'] ?? null;

        return $kube->delete($api_version, $kind, $namespace, $name, is_string($uid) ? $uid : null);
    }

    /**
     * Start the deployment's own BuildKit daemon (Manifests::buildkitd) and
     * return its buildctl address once it is ready.
     */
    static function start_buildkitd(Runtime $runtime, Task_log $log, int $deployment_id): string {
        $kube = $runtime->kube();
        $name = Manifests::buildkitd_name($deployment_id);
        // Outlives the build step's own timeout, so the pod never ends mid-build
        $deadline_seconds = Runtime::SHELL_TIMEOUT_SECONDS + self::BUILDKITD_READY_SECONDS + 120;

        $log->info("Starting build daemon {$name}");
        $kube->apply(Manifests::buildkitd($deployment_id, $deadline_seconds));

        $until = time() + self::BUILDKITD_READY_SECONDS;

        while (time() < $until) {
            $pod = $kube->get('v1', 'Pod', Manifests::BUILDKIT_NAMESPACE, $name);
            $phase = (string) ($pod['status']['phase'] ?? '');
            $ip = (string) ($pod['status']['podIP'] ?? '');

            if ($phase === 'Failed' || $phase === 'Succeeded') {
                throw new RuntimeException("Build daemon {$name} stopped ({$phase})");
            }

            foreach ($pod['status']['conditions'] ?? [] as $condition) {
                if (($condition['type'] ?? '') === 'Ready' && ($condition['status'] ?? '') === 'True' && $ip !== '') {
                    $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
                    return "tcp://{$host}:" . Manifests::BUILDKIT_PORT;
                }
            }

            sleep(2);
        }

        throw new RuntimeException("Build daemon {$name} was not ready within " . self::BUILDKITD_READY_SECONDS . 's');
    }

    /**
     * Delete the deployment's BuildKit daemon. Never throws: a leftover pod
     * still ends at its activeDeadlineSeconds.
     */
    static function stop_buildkitd(Runtime $runtime, Task_log $log, int $deployment_id): void {
        $name = Manifests::buildkitd_name($deployment_id);

        try {
            $runtime->kube()->delete('v1', 'Pod', Manifests::BUILDKIT_NAMESPACE, $name);
        } catch (Throwable $e) {
            $log->warn("Could not delete build daemon {$name}: {$e->getMessage()}");
        }
    }

    /**
     * Fail when an unpacked (untrusted) source tree has a symlink that resolves
     * outside it, or does not resolve at all. Directory links are not followed.
     */
    static function assert_no_escaping_symlinks(string $dir): void {
        $root = realpath($dir);

        if ($root === false) {
            throw new RuntimeException('Source directory not found');
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            if (!$file->isLink()) {
                continue;
            }

            $target = realpath($file->getPathname());

            if ($target === false || ($target !== $root && !str_starts_with($target, $root . '/'))) {
                $path = substr($file->getPathname(), strlen($root) + 1);
                throw new RuntimeException("Symlink {$path} points outside the repository");
            }
        }
    }
}
