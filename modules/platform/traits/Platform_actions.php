<?php

/**
 * Platform actions for models: queue deployments/rollbacks, delete apps and
 * read apps, deployments and logs.
 *
 * Every method takes an explicit project id instead of reading the session, so the
 * same code serves the customer UI, GitHub webhooks, the admin MCP and the
 * worker (Worker_actions). Anything fetched by id is checked against that project.
 *
 * @property-read Db $db
 */
trait Platform_actions {

    /** Subdomains a app slug may not take */
    const RESERVED_SLUGS = ['www', 'api', 'app', 'admin', 'mail', 'mcp', 'oauth', 'status', 'docs', 'registry'];

    /**
     * Queue a deployment of the app's configured source.
     *
     * A app has at most one queued (not yet started) deploy: a new request
     * replaces its commit instead of adding another, so pushes or button presses
     * cannot pile up builds ahead of other projects.
     *
     * @return int The deployment id (new, or the queued one that was updated)
     * @throws RuntimeException when the app is not the project's or has no source
     */
    function queue_deploy(int $project_id, int $app_id, ?int $project_member_id, ?int $customer_id, string $trigger = 'manual', ?string $commit_sha = null): int {
        if (!in_array($trigger, ['manual', 'webhook', 'mcp', 'rollback', 'reconcile'], true)) {
            throw new InvalidArgumentException("Unknown deploy trigger: {$trigger}");
        }

        if ($commit_sha !== null && !preg_match('/^[0-9a-f]{7,40}$/i', $commit_sha)) {
            throw new InvalidArgumentException('Invalid commit SHA');
        }

        $app = $this->_app_or_fail($project_id, $app_id);

        if (empty($app->source_url)) {
            throw new RuntimeException('App has no source repository');
        }

        $this->ensure_app_slug($app_id);

        $commit_sha = $commit_sha !== null ? strtolower($commit_sha) : null;

        return $this->db->transaction(function () use ($project_id, $app, $project_member_id, $customer_id, $trigger, $commit_sha) {
            $queued = $this->_queued_deploy((int) $app->id);

            if ($queued !== false) {
                $deployment_id = (int) $queued->deployment_id;
                $this->db->update($deployment_id, [
                    'project_member_id' => $project_member_id,
                    'customer_id' => $customer_id,
                    'commit_sha' => $commit_sha,
                    'trigger_type' => $trigger,
                ], 'deployment');
                $this->db->update((int) $queued->task_id, [
                    'payload' => json_encode([
                        'deployment_id' => $deployment_id,
                        'app_id' => (int) $app->id,
                        'project_id' => $project_id,
                        'commit_sha' => $commit_sha,
                    ]),
                ], 'tasks');

                return $deployment_id;
            }

            $deployment_id = $this->db->insert([
                'project_id' => $project_id,
                'project_member_id' => $project_member_id,
                'customer_id' => $customer_id,
                'app_id' => (int) $app->id,
                'status' => 'pending',
                'source_type' => 'git',
                'source_url' => $app->source_url,
                'commit_sha' => $commit_sha,
                'trigger_type' => $trigger,
            ], 'deployment');

            $this->_queue_task($project_id, (int) $app->id, $deployment_id, 'deploy', [
                'deployment_id' => $deployment_id,
                'app_id' => (int) $app->id,
                'project_id' => $project_id,
                'commit_sha' => $commit_sha,
            ]);

            return $deployment_id;
        });
    }

    /**
     * Queue a rollback to a previously built image.
     *
     * Without a target, rolls back to the latest successful deployment before
     * the app's current one.
     *
     * @return int The new (rollback) deployment id
     * @throws RuntimeException when the app/target is not the project's or no image is available
     */
    function queue_rollback(int $project_id, int $app_id, ?int $customer_id, ?int $target_deployment_id = null): int {
        $app = $this->_app_or_fail($project_id, $app_id);

        $target = $target_deployment_id !== null
            ? $this->find_project_deployment($project_id, $target_deployment_id)
            : $this->_previous_successful_deployment($app);

        if ($target === false || (int) $target->app_id !== $app_id) {
            throw new RuntimeException('No deployment to roll back to');
        }

        if ($target->status !== 'success' && $target->status !== 'rolled_back') {
            throw new RuntimeException('Can only roll back to a deployment that succeeded');
        }

        if (empty($target->image)) {
            throw new RuntimeException('Target deployment has no image');
        }

        return $this->db->transaction(function () use ($project_id, $app_id, $customer_id, $target) {
            $deployment_id = $this->db->insert([
                'project_id' => $project_id,
                'project_member_id' => null,
                'customer_id' => $customer_id,
                'app_id' => $app_id,
                'status' => 'pending',
                'source_type' => $target->source_type,
                'source_url' => $target->source_url,
                'commit_sha' => $target->commit_sha,
                'image' => $target->image,
                'trigger_type' => 'rollback',
            ], 'deployment');

            $this->_queue_task($project_id, $app_id, $deployment_id, 'rollback', [
                'deployment_id' => $deployment_id,
                'app_id' => $app_id,
                'project_id' => $project_id,
                'target_deployment_id' => (int) $target->id,
                'image' => $target->image,
            ]);

            return $deployment_id;
        });
    }

    /**
     * Delete a app: soft-delete its row (it disappears everywhere) and queue
     * destroy_app, which removes its app from the cluster but keeps its
     * database and files (see that task for exactly what is kept).
     *
     * Deploys that have not started yet are cancelled. While one is running the
     * delete is refused, so a rollout can never recreate the app after cleanup.
     *
     * The row is hidden before the app is gone. If destroy_app fails, the
     * admin MCP lists it (audit_app_resources) and re-queues it
     * (retry_app_cleanup, requeue_app_destroy()).
     *
     * @return int The destroy_app task id
     * @throws RuntimeException when the app is not the project's or a deployment is running
     */
    function queue_app_delete(int $project_id, int $app_id): int {
        $app = $this->_app_or_fail($project_id, $app_id);

        return $this->db->transaction(function () use ($project_id, $app) {
            $app_id = (int) $app->id;

            // Row lock: a second, concurrent delete waits here, then finds it gone
            $live = $this->db->query_bind(
                'SELECT id FROM app WHERE id = :app_id AND project_id = :project_id AND deleted_at IS NULL FOR UPDATE',
                ['app_id' => $app_id, 'project_id' => $project_id],
                'column'
            );

            if ($live === false) {
                throw new RuntimeException('App not found');
            }

            foreach ($this->_open_app_tasks($app_id) as $task) {
                if ($task->started_at !== null) {
                    throw new RuntimeException('A deployment is running. Wait for it to finish, then delete the app.');
                }

                $this->db->query_bind(
                    "UPDATE tasks SET failed_at = NOW(), error_message = 'App deleted' WHERE id = :id",
                    ['id' => (int) $task->id]
                );

                if ($task->deployment_id !== null) {
                    $this->db->query_bind(
                        "UPDATE deployment SET status = 'failed', completed_at = NOW() WHERE id = :id AND status = 'pending'",
                        ['id' => (int) $task->deployment_id]
                    );
                }
            }

            $this->db->query_bind(
                'UPDATE app SET deleted_at = NOW() WHERE id = :app_id AND project_id = :project_id AND deleted_at IS NULL',
                ['app_id' => $app_id, 'project_id' => $project_id]
            );

            return $this->_queue_task($project_id, $app_id, null, 'destroy_app', [
                'app_id' => $app_id,
                'project_id' => $project_id,
            ]);
        });
    }

    /**
     * Queue destroy_app again for a app that is already deleted, after
     * the first run failed (an API outage, an object that was not ours, ...).
     * A deleted app is hidden everywhere else, so this is the only way to
     * finish its cleanup; until then its app may still serve traffic and its
     * verified domain stays held. The worker never retries on its own.
     *
     * @return int The destroy_app task id
     * @throws RuntimeException when the app is not a deleted app of the project, or its cleanup is still queued or running
     */
    function requeue_app_destroy(int $project_id, int $app_id): int {
        return $this->db->transaction(function () use ($project_id, $app_id) {
            $deleted = $this->db->query_bind(
                'SELECT id FROM app WHERE id = :app_id AND project_id = :project_id AND deleted_at IS NOT NULL FOR UPDATE',
                ['app_id' => $app_id, 'project_id' => $project_id],
                'column'
            );

            if ($deleted === false) {
                throw new RuntimeException('No deleted app with that id');
            }

            if ($this->_open_app_tasks($app_id) !== []) {
                throw new RuntimeException('Its cleanup is already queued or running');
            }

            return $this->_queue_task($project_id, $app_id, null, 'destroy_app', [
                'app_id' => $app_id,
                'project_id' => $project_id,
            ]);
        });
    }

    /**
     * The project's apps with their current deployment's state.
     * Leaves out webhook_secret so the rows are safe to return to API clients.
     */
    function project_apps(int $project_id): array {
        $sql = <<<SQL
            SELECT
                p.id, p.project_id, p.label, p.slug, p.type, p.domain, p.verified_domain,
                p.domain_pointed_at, p.domain_secured_at, p.domain_checked_at,
                p.source_provider, p.source_url, p.source_branch,
                p.persistent_paths, p.php_extensions, p.always_on, p.resource_tier,
                p.database_server_id, p.current_deployment_id, p.created_at,
                cd.status AS current_status,
                cd.commit_sha AS current_commit_sha,
                cd.image AS current_image,
                cd.completed_at AS current_deployed_at
            FROM app p
            LEFT JOIN deployment cd ON cd.id = p.current_deployment_id
            WHERE p.project_id = :project_id AND p.deleted_at IS NULL
            ORDER BY p.label ASC
        SQL;
        return $this->db->query_bind($sql, ['project_id' => $project_id], 'object');
    }

    /**
     * A app row (including the webhook_secret seed) if it belongs to the project
     * and is not deleted. Every action on a app starts here.
     */
    function find_project_app(int $project_id, int $app_id): object|false {
        $sql = <<<SQL
            SELECT *
            FROM app
            WHERE id = :app_id AND project_id = :project_id AND deleted_at IS NULL
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['app_id' => $app_id, 'project_id' => $project_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * The app's latest deployments, newest first.
     */
    function app_deployments(int $project_id, int $app_id, int $limit = 20): array {
        $limit = max(1, min($limit, 100));

        $sql = <<<SQL
            SELECT d.*, (p.current_deployment_id = d.id) AS is_current
            FROM deployment d
            JOIN app p ON p.id = d.app_id
            WHERE d.app_id = :app_id AND d.project_id = :project_id
            ORDER BY d.id DESC
            LIMIT {$limit}
        SQL;
        return $this->db->query_bind($sql, ['app_id' => $app_id, 'project_id' => $project_id], 'object');
    }

    /**
     * A deployment (with its app's label and slug) if it belongs to the project.
     */
    function find_project_deployment(int $project_id, int $deployment_id): object|false {
        $sql = <<<SQL
            SELECT d.*, p.label AS app_label, p.slug AS app_slug
            FROM deployment d
            JOIN app p ON p.id = d.app_id
            WHERE d.id = :deployment_id AND d.project_id = :project_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['deployment_id' => $deployment_id, 'project_id' => $project_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * Log lines from the deployment's tasks after a given log id, oldest first.
     * Empty when the deployment is not the project's.
     */
    function deployment_logs(int $project_id, int $deployment_id, int $after_id = 0, int $limit = 200): array {
        $limit = max(1, min($limit, 1000));

        $sql = <<<SQL
            SELECT l.id, l.task_id, t.script, l.level, l.message, l.created_at
            FROM task_logs l
            JOIN tasks t ON t.id = l.task_id
            JOIN deployment d ON d.id = t.deployment_id
            WHERE d.id = :deployment_id
              AND d.project_id = :project_id
              AND l.id > :after_id
            ORDER BY l.id ASC
            LIMIT {$limit}
        SQL;
        return $this->db->query_bind($sql, [
            'deployment_id' => $deployment_id,
            'project_id' => $project_id,
            'after_id' => $after_id,
        ], 'object');
    }

    /**
     * A DNS-label safe, unique slug for a app label (max 40 chars).
     */
    function generate_app_slug(string $label, ?int $ignore_app_id = null): string {
        $base = strtolower(trim($label));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim(substr(trim($base, '-'), 0, 32), '-');

        if ($base === '' || in_array($base, self::RESERVED_SLUGS, true)) {
            $base = $base === '' ? 'app' : $base . '-app';
        }

        $slug = $base;
        $suffix = 2;
        while ($this->_slug_taken($slug, $ignore_app_id)) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    /**
     * The app's slug, generating and saving one if missing.
     */
    function ensure_app_slug(int $app_id): string {
        $rows = $this->db->query_bind(
            'SELECT id, label, slug FROM app WHERE id = :id LIMIT 1',
            ['id' => $app_id],
            'object'
        );
        $app = $rows[0] ?? throw new RuntimeException('App not found');

        if (!empty($app->slug)) {
            return $app->slug;
        }

        $slug = $this->generate_app_slug($app->label, $app_id);
        $this->db->update($app_id, ['slug' => $slug], 'app');

        return $slug;
    }

    /**
     * The DNS TXT record that proves a customer controls the app's requested
     * domain: name "_trongate-cloud.{domain}", value an HMAC of app + domain.
     *
     * @return array{name: string, value: string}
     */
    function domain_verification_record(int $app_id, string $domain): array {
        if (APP_KEY === '') {
            throw new RuntimeException('APP_KEY is required to derive domain tokens');
        }

        return [
            'name' => '_trongate-cloud.' . $domain,
            'value' => 'trongate-cloud-verify=' . substr(hash_hmac('sha256', "domain:{$app_id}:{$domain}", APP_KEY), 0, 32),
        ];
    }

    /**
     * Check the TXT record and, when it matches, make the requested domain the
     * app's verified domain. It is routed once its DNS also points at the
     * platform (the gate in Manifests::custom_domains): this queues a
     * recheck_domains task, which promotes it right away if it already does.
     * Pressing Verify again on a verified domain queues another check.
     *
     * @return bool|string true, or why it failed
     */
    function verify_app_domain(int $project_id, int $app_id): bool|string {
        $app = $this->_app_or_fail($project_id, $app_id);
        $domain = (string) ($app->domain ?? '');

        if ($domain === '') {
            return 'The app has no custom domain.';
        }

        if ($domain === (string) ($app->verified_domain ?? '')) {
            $this->queue_domain_recheck($project_id, $app_id);
            return true;
        }

        $record = $this->domain_verification_record($app_id, $domain);
        $found = false;

        foreach (@dns_get_record($record['name'], DNS_TXT) ?: [] as $txt) {
            if (hash_equals($record['value'], trim((string) ($txt['txt'] ?? '')))) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return 'The TXT record was not found yet. DNS changes can take a while to appear.';
        }

        try {
            $this->db->update($app_id, [
                'verified_domain' => $domain,
                'domain_pointed_at' => null,
                'domain_dns_failures' => 0,
                'domain_checked_at' => null,
                'domain_secured_at' => null,
            ], 'app');
        } catch (PDOException $e) {
            // unique_verified_domain: another app already serves it
            return 'This domain is already used by another app.';
        }

        $this->queue_domain_recheck($project_id, $app_id);

        return true;
    }

    /**
     * Queue a DNS check of the app's verified domain (tasks/recheck_domains.php),
     * unless one is already waiting
     */
    function queue_domain_recheck(int $project_id, int $app_id): void {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM tasks
            WHERE app_id = :app_id
              AND script = 'recheck_domains'
              AND started_at IS NULL
              AND completed_at IS NULL
              AND failed_at IS NULL
        SQL;
        $waiting = (int) $this->db->query_bind($sql, ['app_id' => $app_id], 'column');

        if ($waiting === 0) {
            $this->_queue_task($project_id, $app_id, null, 'recheck_domains', [
                'project_id' => $project_id,
                'app_id' => $app_id,
            ]);
        }
    }

    /**
     * 64 random hex chars stored in app.webhook_secret. This is only a seed:
     * the secret GitHub signs with is webhook_signing_secret(), so a database leak
     * alone does not reveal it.
     */
    function generate_webhook_secret(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * The secret GitHub signs webhooks with (X-Hub-Signature-256): an HMAC of the
     * stored seed keyed with APP_KEY. Rotating APP_KEY changes every webhook secret.
     */
    function webhook_signing_secret(int $app_id, string $seed): string {
        if (APP_KEY === '') {
            throw new RuntimeException('APP_KEY is required to derive webhook secrets');
        }

        return hash_hmac('sha256', "webhook:{$app_id}:{$seed}", APP_KEY);
    }

    /**
     * The app's webhook signing secret, generating and saving a seed if missing.
     */
    function ensure_webhook_secret(int $project_id, int $app_id): string {
        $app = $this->_app_or_fail($project_id, $app_id);
        $seed = (string) ($app->webhook_secret ?? '');

        if ($seed === '') {
            $seed = $this->generate_webhook_secret();
            $this->db->update($app_id, ['webhook_secret' => $seed], 'app');
        }

        return $this->webhook_signing_secret($app_id, $seed);
    }

    function _app_or_fail(int $project_id, int $app_id): object {
        $app = $this->find_project_app($project_id, $app_id);

        if ($app === false) {
            throw new RuntimeException('App not found');
        }

        return $app;
    }

    function _queue_task(int $project_id, ?int $app_id, ?int $deployment_id, string $script, array $payload): int {
        return $this->db->insert([
            'project_id' => $project_id,
            'app_id' => $app_id,
            'deployment_id' => $deployment_id,
            'script' => $script,
            'payload' => json_encode($payload),
        ], 'tasks');
    }

    /**
     * The app's deploy task that no worker has claimed yet, row-locked for
     * the surrounding transaction. A worker holding the row (FOR UPDATE SKIP LOCKED
     * in Runtime::pick_task) makes this wait, then return false once started.
     */
    function _queued_deploy(int $app_id): object|false {
        $sql = <<<SQL
            SELECT t.id AS task_id, d.id AS deployment_id
            FROM tasks t
            JOIN deployment d ON d.id = t.deployment_id
            WHERE t.app_id = :app_id
              AND t.script = 'deploy'
              AND t.started_at IS NULL
              AND t.completed_at IS NULL
              AND t.failed_at IS NULL
              AND d.status = 'pending'
            ORDER BY t.id DESC
            LIMIT 1
            FOR UPDATE
        SQL;
        $rows = $this->db->query_bind($sql, ['app_id' => $app_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * The app's unfinished tasks, row-locked for the surrounding transaction.
     * A worker claiming one of them (Runtime::pick_task) makes this wait until
     * the claim is committed, so started_at is reliable.
     */
    function _open_app_tasks(int $app_id): array {
        $sql = <<<SQL
            SELECT id, deployment_id, started_at
            FROM tasks
            WHERE app_id = :app_id
              AND completed_at IS NULL
              AND failed_at IS NULL
            ORDER BY id
            FOR UPDATE
        SQL;
        return $this->db->query_bind($sql, ['app_id' => $app_id], 'object');
    }

    function _previous_successful_deployment(object $app): object|false {
        $sql = <<<SQL
            SELECT d.*, p.label AS app_label, p.slug AS app_slug
            FROM deployment d
            JOIN app p ON p.id = d.app_id
            WHERE d.app_id = :app_id
              AND d.project_id = :project_id
              AND d.status IN ('success', 'rolled_back')
              AND d.image IS NOT NULL
              AND d.id <> :current_id
              AND d.id < :before_id
            ORDER BY d.id DESC
            LIMIT 1
        SQL;
        $current_id = (int) ($app->current_deployment_id ?? 0);
        $rows = $this->db->query_bind($sql, [
            'app_id' => (int) $app->id,
            'project_id' => (int) $app->project_id,
            'current_id' => $current_id,
            'before_id' => $current_id > 0 ? $current_id : PHP_INT_MAX,
        ], 'object');

        return $rows[0] ?? false;
    }

    function _slug_taken(string $slug, ?int $ignore_app_id): bool {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM app
            WHERE slug = :slug AND id <> :ignore_id
        SQL;
        $count = $this->db->query_bind($sql, ['slug' => $slug, 'ignore_id' => $ignore_app_id ?? 0], 'column');

        return (int) $count > 0;
    }
}
