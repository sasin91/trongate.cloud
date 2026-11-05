<?php

require_once __DIR__ . '/../manifests/Manifests.php';
require_once __DIR__ . '/../manifests/Project_service.php';
require_once __DIR__ . '/../manifests/Domain_dns.php';

/**
 * Platform actions for models: projects and their members, queue
 * deployments/rollbacks, delete apps and projects, add and remove services
 * (Project_service), and read apps, deployments and logs.
 *
 * Every method takes an explicit project (or customer) id instead of reading
 * the session, so the same code serves the customer UI, GitHub webhooks, the
 * admin MCP and the worker (Worker_actions). Anything fetched by id is checked
 * against that project. The find_member_* methods are the customer UI's way
 * in: they only return what a member of the project may see.
 *
 * @property-read Db $db
 */
trait Platform_actions {

    /** Subdomains an app slug may not take */
    const RESERVED_SLUGS = ['www', 'api', 'app', 'admin', 'mail', 'mcp', 'oauth', 'status', 'docs', 'registry'];

    /**
     * Queue a deployment of the app's configured source.
     *
     * An app has at most one queued (not yet started) deploy: a new request
     * replaces its commit instead of adding another, so pushes or button presses
     * cannot pile up builds ahead of other apps.
     *
     * @return int The deployment id (new, or the queued one that was updated)
     * @throws RuntimeException when the app is not the project's or has no source
     */
    function queue_deploy(int $project_id, int $app_id, ?int $customer_id, string $trigger = 'manual', ?string $commit_sha = null): int {
        if (!in_array($trigger, ['manual', 'webhook', 'mcp', 'rollback', 'reconcile', 'platform'], true)) {
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

        return $this->db->transaction(function () use ($project_id, $app, $customer_id, $trigger, $commit_sha) {
            $queued = $this->_queued_deploy((int) $app->id);

            if ($queued !== false) {
                $deployment_id = (int) $queued->deployment_id;
                $this->db->update($deployment_id, [
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
                'customer_id' => $customer_id,
                'app_id' => (int) $app->id,
                'status' => 'pending',
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

        if (empty($target->config_files)) {
            throw new RuntimeException("Deployment #{$target->id} was built before trongate.cloud kept a copy of its config files, so it cannot be rolled back to. Deploy the app instead.");
        }

        return $this->db->transaction(function () use ($project_id, $app_id, $customer_id, $target) {
            $deployment_id = $this->db->insert([
                'project_id' => $project_id,
                'customer_id' => $customer_id,
                'app_id' => $app_id,
                'status' => 'pending',
                'source_url' => $target->source_url,
                'commit_sha' => $target->commit_sha,
                'image' => $target->image,
                'config_files' => $target->config_files,
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
     * Delete an app: soft-delete its row (it disappears everywhere) and queue
     * destroy_app, which removes it from the cluster but keeps its database
     * and files (see that task for exactly what is kept).
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

        // A production app takes its staging app with it, first, so a staging app never outlives it
        $staging = $this->find_staging_app($app_id);

        if ($staging !== false) {
            $this->queue_app_delete($project_id, (int) $staging->id);
        }

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

            // The app's environment variables (App_env) go with it; the cluster's
            // copy, the p{id}-env Secret, is removed by destroy_app
            $this->db->query_bind(
                "DELETE FROM secrets WHERE module = 'app' AND module_id = :app_id",
                ['app_id' => $app_id]
            );

            return $this->_queue_task($project_id, $app_id, null, 'destroy_app', [
                'app_id' => $app_id,
                'project_id' => $project_id,
            ]);
        });
    }

    /**
     * Queue destroy_app again for an app that is already deleted, after
     * the first run failed (an API outage, an object that was not ours, ...).
     * A deleted app is hidden everywhere else, so this is the only way to
     * finish its cleanup; until then it may still serve traffic and its
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
     * The project's production apps with their current deployment's state and
     * staging_app_id (tasks/split_staging.php), NULL when they have none.
     * Lists columns explicitly so the rows are safe to return to API clients.
     */
    function project_apps(int $project_id): array {
        $sql = <<<SQL
            SELECT
                a.id, a.project_id, a.label, a.slug, a.type, a.environment, a.domain, a.verified_domain,
                a.domain_pointed_at, a.domain_secured_at, a.domain_checked_at,
                a.source_provider, a.source_url, a.source_branch,
                a.database_server_id, a.current_deployment_id, a.created_at,
                r.php_extensions, r.build_commands, r.persistent_paths, r.always_on, r.trongate_env, r.default_module, r.captures_mail, r.health_check_path,
                st.id AS staging_app_id,
                cd.status AS current_status,
                cd.commit_sha AS current_commit_sha,
                cd.image AS current_image,
                cd.completed_at AS current_deployed_at
            FROM app a
            LEFT JOIN app_runtime r ON r.app_id = a.id
            LEFT JOIN deployment cd ON cd.id = a.current_deployment_id
            LEFT JOIN app st ON st.production_app_id = a.id AND st.environment = 'staging' AND st.deleted_at IS NULL
            WHERE a.project_id = :project_id AND a.deleted_at IS NULL AND a.environment = 'production'
            ORDER BY a.label ASC
        SQL;
        return $this->db->query_bind($sql, ['project_id' => $project_id], 'object');
    }

    /**
     * An app row with its runtime settings (app_runtime) and its project's
     * namespace, if it belongs to the project and neither is deleted. Every
     * action on an app starts here.
     */
    function find_project_app(int $project_id, int $app_id): object|false {
        $sql = <<<SQL
            SELECT a.*, r.php_extensions, r.build_commands, r.persistent_paths, r.always_on, r.trongate_env, r.default_module, r.captures_mail, r.health_check_path, p.namespace
            FROM app a
            JOIN project p ON p.id = a.project_id
            LEFT JOIN app_runtime r ON r.app_id = a.id
            WHERE a.id = :app_id AND a.project_id = :project_id
              AND a.deleted_at IS NULL AND p.deleted_at IS NULL
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
            SELECT d.*, (a.current_deployment_id = d.id) AS is_current
            FROM deployment d
            JOIN app a ON a.id = d.app_id
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
            SELECT d.*, a.label AS app_label, a.slug AS app_slug
            FROM deployment d
            JOIN app a ON a.id = d.app_id
            WHERE d.id = :deployment_id AND d.project_id = :project_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['deployment_id' => $deployment_id, 'project_id' => $project_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * The 'step' lines of a deployment's log (Task_log::step), oldest first:
     * where it entered each step of the deploy (Deploy_steps)
     */
    function deployment_step_marks(int $project_id, int $deployment_id): array {
        $sql = <<<SQL
            SELECT l.id, l.message, l.created_at
            FROM task_logs l
            JOIN tasks t ON t.id = l.task_id
            JOIN deployment d ON d.id = t.deployment_id
            WHERE d.id = :deployment_id
              AND d.project_id = :project_id
              AND l.level = 'step'
            ORDER BY l.id ASC
        SQL;
        return $this->db->query_bind($sql, [
            'deployment_id' => $deployment_id,
            'project_id' => $project_id,
        ], 'object');
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
     * A DNS-label safe, unique slug for an app label (max 40 chars).
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

        foreach (Domain_dns::txt_values($record['name']) as $value) {
            if (hash_equals($record['value'], $value)) {
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
     * Queue split_staging for a production app: its {slug} host moves to a
     * new staging app (a copy deploying its own branch) and production keeps
     * only its domain. recheck_domains does this by itself when a domain first
     * goes live; this is for apps that went live earlier or lost their
     * staging app. A split already waiting is not queued again.
     *
     * @throws RuntimeException when the app is not a production app with a secured domain, or already has a staging app
     */
    function queue_staging_split(int $project_id, int $app_id): void {
        $app = $this->_app_or_fail($project_id, $app_id);

        if ($app->environment !== 'production' || Manifests::secured_domains((array) $app) === []) {
            throw new RuntimeException('Staging is set up once the custom domain is live with HTTPS.');
        }

        if ($this->find_staging_app($app_id) !== false) {
            throw new RuntimeException('This app already has a staging app.');
        }

        $sql = <<<SQL
            SELECT COUNT(*)
            FROM tasks
            WHERE app_id = :app_id
              AND script = 'split_staging'
              AND completed_at IS NULL
              AND failed_at IS NULL
        SQL;

        if ((int) $this->db->query_bind($sql, ['app_id' => $app_id], 'column') === 0) {
            $this->_queue_task($project_id, $app_id, null, 'split_staging', [
                'project_id' => $project_id,
                'app_id' => $app_id,
            ]);
        }
    }

    /**
     * Queue import_git: the app's GitHub repository, every branch with its
     * history, is copied to trongate.cloud git, and the app and its staging
     * app build from there. A move already waiting is not queued again. The
     * task itself refuses an app it cannot move (not production, not GitHub).
     */
    function queue_git_import(int $project_id, int $app_id, ?int $customer_id): void {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM tasks
            WHERE app_id = :app_id
              AND script = 'import_git'
              AND completed_at IS NULL
              AND failed_at IS NULL
        SQL;

        if ((int) $this->db->query_bind($sql, ['app_id' => $app_id], 'column') === 0) {
            $this->_queue_task($project_id, $app_id, null, 'import_git', [
                'project_id' => $project_id,
                'app_id' => $app_id,
                'customer_id' => $customer_id,
            ]);
        }
    }

    /**
     * A production app's staging app (id, slug, source_branch), if it has one
     */
    function find_staging_app(int $production_app_id): object|false {
        $sql = <<<SQL
            SELECT id, slug, source_branch
            FROM app
            WHERE production_app_id = :app_id AND environment = 'staging' AND deleted_at IS NULL
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['app_id' => $production_app_id], 'object');

        return $rows[0] ?? false;
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

    // -------------------------------------------------------------------------
    // Projects and members
    // -------------------------------------------------------------------------

    /**
     * Create a project owned by the customer: its row, with the namespace
     * Manifests::new_namespace() gives its id, and the owner's membership, in
     * one transaction. The namespace itself is created by the first deploy.
     *
     * @return int The new project id
     * @throws InvalidArgumentException when the name is empty or too long
     */
    function create_project(int $owner_customer_id, string $name): int {
        $name = $this->_clean_project_name($name);

        return $this->db->transaction(function () use ($owner_customer_id, $name) {
            // namespace is NOT NULL UNIQUE and derives from the id: a unique
            // placeholder until the id is known, never visible outside this transaction
            $project_id = $this->db->insert([
                'name' => $name,
                'namespace' => 'new-' . bin2hex(random_bytes(8)),
                'owner_id' => $owner_customer_id,
            ], 'project');

            $this->db->update($project_id, ['namespace' => Manifests::new_namespace($project_id)], 'project');

            $this->db->insert([
                'project_id' => $project_id,
                'customer_id' => $owner_customer_id,
                'role' => 'owner',
            ], 'project_member');

            return $project_id;
        });
    }

    /**
     * A project the customer is a member of (not deleted), with their
     * role as member_role.
     */
    function find_member_project(int $customer_id, int $project_id): object|false {
        $sql = <<<SQL
            SELECT p.*, pm.role AS member_role
            FROM project p
            JOIN project_member pm ON pm.project_id = p.id
            WHERE p.id = :project_id
              AND p.deleted_at IS NULL
              AND pm.customer_id = :customer_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['project_id' => $project_id, 'customer_id' => $customer_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * The customer's projects (not deleted), by name, with
     * their role (member_role) and how many apps each holds (app_count).
     */
    function member_projects(int $customer_id): array {
        $sql = <<<SQL
            SELECT p.id, p.name, p.namespace, p.owner_id, p.created_at,
                pm.role AS member_role,
                COUNT(a.id) AS app_count
            FROM project p
            JOIN project_member pm ON pm.project_id = p.id
            LEFT JOIN app a ON a.project_id = p.id AND a.deleted_at IS NULL
            WHERE pm.customer_id = :customer_id
              AND p.deleted_at IS NULL
            GROUP BY p.id, p.name, p.namespace, p.owner_id, p.created_at, pm.role, pm.id
            ORDER BY p.name ASC, p.id ASC
        SQL;
        return $this->db->query_bind($sql, ['customer_id' => $customer_id], 'object');
    }

    /**
     * An app (not deleted) in a project the customer is a member of:
     * the app row with its runtime settings (app_runtime) plus project_name, namespace,
     * and member_role. Pass (int) $app->project_id on to the
     * other actions.
     */
    function find_member_app(int $customer_id, int $app_id): object|false {
        $sql = <<<SQL
            SELECT a.*, r.php_extensions, r.build_commands, r.persistent_paths, r.always_on, r.trongate_env, r.default_module, r.captures_mail, r.health_check_path,
                p.name AS project_name, p.namespace, pm.role AS member_role
            FROM app a
            JOIN project p ON p.id = a.project_id
            LEFT JOIN app_runtime r ON r.app_id = a.id
            JOIN project_member pm ON pm.project_id = p.id
            WHERE a.id = :app_id
              AND a.deleted_at IS NULL
              AND p.deleted_at IS NULL
              AND pm.customer_id = :customer_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['app_id' => $app_id, 'customer_id' => $customer_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * A deployment (with its app's label and slug, and the member's role as
     * member_role) of an app in a project the customer is a member
     * of. Deployments of deleted apps stay readable, like their logs.
     */
    function find_member_deployment(int $customer_id, int $deployment_id): object|false {
        $sql = <<<SQL
            SELECT d.*, a.label AS app_label, a.slug AS app_slug, pm.role AS member_role
            FROM deployment d
            JOIN app a ON a.id = d.app_id
            JOIN project p ON p.id = d.project_id
            JOIN project_member pm ON pm.project_id = p.id
            WHERE d.id = :deployment_id
              AND p.deleted_at IS NULL
              AND pm.customer_id = :customer_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['deployment_id' => $deployment_id, 'customer_id' => $customer_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * The project's members: the owner first, then by email.
     * Rows: id (membership), customer_id, email, role, created_at.
     */
    function project_members(int $project_id): array {
        $sql = <<<SQL
            SELECT pm.id, pm.customer_id, c.email, pm.role, pm.created_at
            FROM project_member pm
            JOIN customer c ON c.id = pm.customer_id
            WHERE pm.project_id = :project_id
            ORDER BY pm.role = 'owner' DESC, c.email ASC
        SQL;
        return $this->db->query_bind($sql, ['project_id' => $project_id], 'object');
    }

    /**
     * Add an existing customer account to the project as a member, by email.
     *
     * @return bool|string true, or why not (safe to show the owner)
     */
    function add_project_member(int $project_id, string $email): bool|string {
        $email = strtolower(trim($email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Enter a valid email address.';
        }

        $customer_id = $this->db->query_bind(
            'SELECT id FROM customer WHERE email = :email AND active = 1 LIMIT 1',
            ['email' => $email],
            'column'
        );

        if ($customer_id === false) {
            return 'No Trongate Cloud account uses that email address. Ask them to sign up first.';
        }

        $member = $this->db->query_bind(
            'SELECT id FROM project_member WHERE project_id = :project_id AND customer_id = :customer_id LIMIT 1',
            ['project_id' => $project_id, 'customer_id' => (int) $customer_id],
            'column'
        );

        if ($member !== false) {
            return 'They are already a member of this project.';
        }

        if ($this->_live_project_id($project_id) === false) {
            return 'Project not found.';
        }

        $this->db->insert([
            'project_id' => $project_id,
            'customer_id' => (int) $customer_id,
            'role' => 'member',
        ], 'project_member');

        return true;
    }

    /**
     * Take a member out of the project. The owner can't be removed.
     *
     * @return bool|string true, or why not (safe to show the owner)
     */
    function remove_project_member(int $project_id, int $customer_id): bool|string {
        $sql = <<<SQL
            SELECT pm.id, pm.role, p.owner_id
            FROM project_member pm
            JOIN project p ON p.id = pm.project_id
            WHERE pm.project_id = :project_id
              AND pm.customer_id = :customer_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['project_id' => $project_id, 'customer_id' => $customer_id], 'object');
        $member = $rows[0] ?? false;

        if ($member === false) {
            return 'They are not a member of this project.';
        }

        if ($member->role === 'owner' || (int) $member->owner_id === $customer_id) {
            return "The project's owner can't be removed.";
        }

        $this->db->query_bind('DELETE FROM project_member WHERE id = :id', ['id' => (int) $member->id]);

        return true;
    }

    /**
     * @return bool|string true, or why not (safe to show the owner)
     */
    function rename_project(int $project_id, string $name): bool|string {
        try {
            $name = $this->_clean_project_name($name);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        if ($this->_live_project_id($project_id) === false) {
            return 'Project not found.';
        }

        $this->db->update($project_id, ['name' => $name], 'project');

        return true;
    }

    /**
     * Delete an empty project: soft-delete its row (it disappears everywhere)
     * and queue destroy_project, which deletes its namespace once the cluster
     * confirms nothing of an app is left in it.
     *
     * Refused while the project has an app, or any unfinished task (an app's
     * destroy_app still running, a deploy, a domain check): delete the apps
     * first and wait for their cleanup.
     *
     * @return int The destroy_project task id
     * @throws RuntimeException when the project is not found or not empty
     */
    function queue_project_delete(int $project_id): int {
        return $this->db->transaction(function () use ($project_id) {
            // Row lock: a concurrent delete waits here, then finds it gone. An
            // app being added to the project waits too (its foreign key check
            // share-locks this row); destroy_project checks for apps again
            // before it deletes anything.
            $live = $this->db->query_bind(
                'SELECT id FROM project WHERE id = :project_id AND deleted_at IS NULL FOR UPDATE',
                ['project_id' => $project_id],
                'column'
            );

            if ($live === false) {
                throw new RuntimeException('Project not found');
            }

            $apps = (int) $this->db->query_bind(
                'SELECT COUNT(*) FROM app WHERE project_id = :project_id AND deleted_at IS NULL',
                ['project_id' => $project_id],
                'column'
            );

            if ($apps > 0) {
                throw new RuntimeException('Delete the apps in this project first.');
            }

            $services = (int) $this->db->query_bind(
                'SELECT COUNT(*) FROM service WHERE project_id = :project_id AND deleted_at IS NULL',
                ['project_id' => $project_id],
                'column'
            );

            if ($services > 0) {
                throw new RuntimeException('Delete the services in this project first.');
            }

            if ($this->_open_project_tasks($project_id) !== []) {
                throw new RuntimeException('An app of this project is still being removed. Try again in a minute.');
            }

            $this->db->query_bind(
                'UPDATE project SET deleted_at = NOW() WHERE id = :project_id AND deleted_at IS NULL',
                ['project_id' => $project_id]
            );

            return $this->_queue_task($project_id, null, null, 'destroy_project', ['project_id' => $project_id]);
        });
    }

    /**
     * Queue destroy_project again for a deleted project whose namespace is
     * still there (the first run failed or found leftovers; see the admin
     * MCP's audit_app_resources). The worker never retries on its own.
     *
     * @return int The destroy_project task id
     * @throws RuntimeException when the project is not deleted, or its cleanup is still queued or running
     */
    function requeue_project_destroy(int $project_id): int {
        return $this->db->transaction(function () use ($project_id) {
            $deleted = $this->db->query_bind(
                'SELECT id FROM project WHERE id = :project_id AND deleted_at IS NOT NULL FOR UPDATE',
                ['project_id' => $project_id],
                'column'
            );

            if ($deleted === false) {
                throw new RuntimeException('No deleted project with that id');
            }

            if ($this->_open_project_tasks($project_id) !== []) {
                throw new RuntimeException('Its cleanup is already queued or running');
            }

            return $this->_queue_task($project_id, null, null, 'destroy_project', ['project_id' => $project_id]);
        });
    }

    /**
     * The project's live services, by name. Lists columns explicitly so the
     * rows are safe to return to API clients.
     */
    function project_services(int $project_id): array {
        $sql = <<<SQL
            SELECT s.id, s.project_id, s.name, s.image, s.port, s.memory_mb, s.deployed_at, s.created_at,
                (
                    SELECT CASE
                        WHEN t.failed_at IS NOT NULL THEN 'failed'
                        WHEN t.completed_at IS NOT NULL THEN 'running'
                        WHEN t.started_at IS NOT NULL THEN 'deploying'
                        ELSE 'queued'
                    END
                    FROM tasks t
                    WHERE t.project_id = s.project_id
                      AND t.script = 'deploy_service'
                      AND JSON_EXTRACT(t.payload, '$.service_id') = s.id
                    ORDER BY t.id DESC
                    LIMIT 1
                ) AS status
            FROM service s
            WHERE s.project_id = :project_id AND s.deleted_at IS NULL
            ORDER BY s.name
        SQL;
        return $this->db->query_bind($sql, ['project_id' => $project_id], 'object');
    }

    function find_project_service(int $project_id, int $service_id): object|false {
        foreach ($this->project_services($project_id) as $service) {
            if ((int) $service->id === $service_id) {
                return $service;
            }
        }

        return false;
    }

    /**
     * Add a service to the project. Its environment variables are saved by the
     * caller (Project_service::MODULE), then queue_service_deploy() starts it,
     * so the worker never runs it without them.
     *
     * @param array{name: string, image: string, port: int, memory_mb: int} $fields
     * @return int The service id
     * @throws InvalidArgumentException when a field is invalid (the message is safe to show)
     */
    function add_service(int $project_id, array $fields): int {
        $name = (string) $fields['name'];
        $image = trim((string) $fields['image']);
        $port = (int) $fields['port'];
        $memory_mb = (int) $fields['memory_mb'];

        foreach ([
            Project_service::name_error($name),
            Project_service::image_error($image),
            Project_service::port_error($port),
            Project_service::memory_error($memory_mb),
        ] as $error) {
            if ($error !== null) {
                throw new InvalidArgumentException($error);
            }
        }

        return $this->db->transaction(function () use ($project_id, $name, $image, $port, $memory_mb) {
            // Row lock on the project: two adds wait for each other, so the count holds
            $live = $this->db->query_bind(
                'SELECT id FROM project WHERE id = :project_id AND deleted_at IS NULL FOR UPDATE',
                ['project_id' => $project_id],
                'column'
            );

            if ($live === false) {
                throw new InvalidArgumentException('Project not found.');
            }

            $services = $this->project_services($project_id);

            if (count($services) >= Project_service::MAX_PER_PROJECT) {
                throw new InvalidArgumentException('A project can have at most ' . Project_service::MAX_PER_PROJECT . ' services.');
            }

            if (in_array($name, array_column($services, 'name'), true)) {
                throw new InvalidArgumentException("This project already has a service called {$name}.");
            }

            return (int) $this->db->insert([
                'project_id' => $project_id,
                'name' => $name,
                'image' => $image,
                'port' => $port,
                'memory_mb' => $memory_mb,
            ], 'service');
        });
    }

    /**
     * Queue deploy_service, which applies the service's manifests (again)
     *
     * @return int The task id
     */
    function queue_service_deploy(int $project_id, int $service_id): int {
        if ($this->find_project_service($project_id, $service_id) === false) {
            throw new RuntimeException('Service not found');
        }

        return $this->_queue_task($project_id, null, null, 'deploy_service', [
            'service_id' => $service_id,
            'project_id' => $project_id,
        ]);
    }

    /**
     * Delete a service: hide the row, drop its environment variables and
     * queue destroy_service. The apps keep its {NAME}_URL until their next deploy.
     *
     * @return int The destroy_service task id
     */
    function queue_service_delete(int $project_id, int $service_id): int {
        return $this->db->transaction(function () use ($project_id, $service_id) {
            $live = $this->db->query_bind(
                'SELECT id FROM service WHERE id = :id AND project_id = :project_id AND deleted_at IS NULL FOR UPDATE',
                ['id' => $service_id, 'project_id' => $project_id],
                'column'
            );

            if ($live === false) {
                throw new RuntimeException('Service not found');
            }

            $this->db->query_bind(
                'UPDATE service SET deleted_at = NOW() WHERE id = :id AND project_id = :project_id',
                ['id' => $service_id, 'project_id' => $project_id]
            );

            $this->db->query_bind(
                'DELETE FROM secrets WHERE module = :module AND module_id = :id',
                ['module' => Project_service::MODULE, 'id' => $service_id]
            );

            return $this->_queue_task($project_id, null, null, 'destroy_service', [
                'service_id' => $service_id,
                'project_id' => $project_id,
            ]);
        });
    }

    /**
     * A trimmed project name of 3 to 50 characters (Projects::NAME_RULES)
     *
     * @throws InvalidArgumentException (the message is safe to show)
     */
    function _clean_project_name(string $name): string {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if (mb_strlen($name) < 3) {
            throw new InvalidArgumentException('The project name needs at least 3 characters.');
        }

        if (mb_strlen($name) > 50) {
            throw new InvalidArgumentException('The project name can be at most 50 characters.');
        }

        return $name;
    }

    function _live_project_id(int $project_id): int|false {
        $id = $this->db->query_bind(
            'SELECT id FROM project WHERE id = :project_id AND deleted_at IS NULL',
            ['project_id' => $project_id],
            'column'
        );

        return $id === false ? false : (int) $id;
    }

    /**
     * The project's unfinished tasks (any app's, or its own), row-locked for
     * the surrounding transaction like _open_app_tasks().
     */
    function _open_project_tasks(int $project_id): array {
        $sql = <<<SQL
            SELECT id, app_id, script, started_at
            FROM tasks
            WHERE project_id = :project_id
              AND completed_at IS NULL
              AND failed_at IS NULL
            ORDER BY id
            FOR UPDATE
        SQL;
        return $this->db->query_bind($sql, ['project_id' => $project_id], 'object');
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
            SELECT d.*, a.label AS app_label, a.slug AS app_slug
            FROM deployment d
            JOIN app a ON a.id = d.app_id
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
