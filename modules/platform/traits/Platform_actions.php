<?php

/**
 * Platform actions for models: queue deployments/rollbacks and read projects,
 * deployments and logs.
 *
 * Every method takes an explicit team id instead of reading the session, so the
 * same code serves the customer UI, GitHub webhooks, the admin MCP and the
 * worker (Worker_actions). Anything fetched by id is checked against that team.
 *
 * @property-read Db $db
 */
trait Platform_actions {

    /** Subdomains a project slug may not take */
    const RESERVED_SLUGS = ['www', 'api', 'app', 'admin', 'mail', 'mcp', 'oauth', 'status', 'docs', 'registry'];

    /**
     * Queue a deployment of the project's configured source.
     *
     * A project has at most one queued (not yet started) deploy: a new request
     * replaces its commit instead of adding another, so pushes or button presses
     * cannot pile up builds ahead of other teams.
     *
     * @return int The deployment id (new, or the queued one that was updated)
     * @throws RuntimeException when the project is not the team's or has no source
     */
    function queue_deploy(int $team_id, int $project_id, ?int $team_member_id, ?int $customer_id, string $trigger = 'manual', ?string $commit_sha = null): int {
        if (!in_array($trigger, ['manual', 'webhook', 'mcp', 'rollback', 'reconcile'], true)) {
            throw new InvalidArgumentException("Unknown deploy trigger: {$trigger}");
        }

        if ($commit_sha !== null && !preg_match('/^[0-9a-f]{7,40}$/i', $commit_sha)) {
            throw new InvalidArgumentException('Invalid commit SHA');
        }

        $project = $this->_project_or_fail($team_id, $project_id);

        if (empty($project->source_url)) {
            throw new RuntimeException('Project has no source repository');
        }

        $this->ensure_project_slug($project_id);

        $commit_sha = $commit_sha !== null ? strtolower($commit_sha) : null;

        return $this->db->transaction(function () use ($team_id, $project, $team_member_id, $customer_id, $trigger, $commit_sha) {
            $queued = $this->_queued_deploy((int) $project->id);

            if ($queued !== false) {
                $deployment_id = (int) $queued->deployment_id;
                $this->db->update($deployment_id, [
                    'team_member_id' => $team_member_id,
                    'customer_id' => $customer_id,
                    'commit_sha' => $commit_sha,
                    'trigger_type' => $trigger,
                ], 'deployment');
                $this->db->update((int) $queued->task_id, [
                    'payload' => json_encode([
                        'deployment_id' => $deployment_id,
                        'project_id' => (int) $project->id,
                        'team_id' => $team_id,
                        'commit_sha' => $commit_sha,
                    ]),
                ], 'tasks');

                return $deployment_id;
            }

            $deployment_id = $this->db->insert([
                'team_id' => $team_id,
                'team_member_id' => $team_member_id,
                'customer_id' => $customer_id,
                'project_id' => (int) $project->id,
                'status' => 'pending',
                'source_type' => 'git',
                'source_url' => $project->source_url,
                'commit_sha' => $commit_sha,
                'trigger_type' => $trigger,
            ], 'deployment');

            $this->_queue_task($team_id, (int) $project->id, $deployment_id, 'deploy', [
                'deployment_id' => $deployment_id,
                'project_id' => (int) $project->id,
                'team_id' => $team_id,
                'commit_sha' => $commit_sha,
            ]);

            return $deployment_id;
        });
    }

    /**
     * Queue a rollback to a previously built image.
     *
     * Without a target, rolls back to the latest successful deployment before
     * the project's current one.
     *
     * @return int The new (rollback) deployment id
     * @throws RuntimeException when the project/target is not the team's or no image is available
     */
    function queue_rollback(int $team_id, int $project_id, ?int $customer_id, ?int $target_deployment_id = null): int {
        $project = $this->_project_or_fail($team_id, $project_id);

        $target = $target_deployment_id !== null
            ? $this->find_team_deployment($team_id, $target_deployment_id)
            : $this->_previous_successful_deployment($project);

        if ($target === false || (int) $target->project_id !== $project_id) {
            throw new RuntimeException('No deployment to roll back to');
        }

        if ($target->status !== 'success' && $target->status !== 'rolled_back') {
            throw new RuntimeException('Can only roll back to a deployment that succeeded');
        }

        if (empty($target->image)) {
            throw new RuntimeException('Target deployment has no image');
        }

        return $this->db->transaction(function () use ($team_id, $project_id, $customer_id, $target) {
            $deployment_id = $this->db->insert([
                'team_id' => $team_id,
                'team_member_id' => null,
                'customer_id' => $customer_id,
                'project_id' => $project_id,
                'status' => 'pending',
                'source_type' => $target->source_type,
                'source_url' => $target->source_url,
                'commit_sha' => $target->commit_sha,
                'image' => $target->image,
                'trigger_type' => 'rollback',
            ], 'deployment');

            $this->_queue_task($team_id, $project_id, $deployment_id, 'rollback', [
                'deployment_id' => $deployment_id,
                'project_id' => $project_id,
                'team_id' => $team_id,
                'target_deployment_id' => (int) $target->id,
                'image' => $target->image,
            ]);

            return $deployment_id;
        });
    }

    /**
     * The team's projects with their current deployment's state.
     * Leaves out webhook_secret so the rows are safe to return to API clients.
     */
    function team_projects(int $team_id): array {
        $sql = <<<SQL
            SELECT
                p.id, p.team_id, p.label, p.slug, p.type, p.domain, p.verified_domain,
                p.source_provider, p.source_url, p.source_branch,
                p.persistent_paths, p.php_extensions, p.always_on, p.resource_tier,
                p.database_server_id, p.current_deployment_id, p.created_at,
                cd.status AS current_status,
                cd.commit_sha AS current_commit_sha,
                cd.image AS current_image,
                cd.completed_at AS current_deployed_at
            FROM project p
            LEFT JOIN deployment cd ON cd.id = p.current_deployment_id
            WHERE p.team_id = :team_id
            ORDER BY p.label ASC
        SQL;
        return $this->db->query_bind($sql, ['team_id' => $team_id], 'object');
    }

    /**
     * A project row (including the webhook_secret seed) if it belongs to the team.
     */
    function find_team_project(int $team_id, int $project_id): object|false {
        $sql = <<<SQL
            SELECT *
            FROM project
            WHERE id = :project_id AND team_id = :team_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['project_id' => $project_id, 'team_id' => $team_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * The project's latest deployments, newest first.
     */
    function project_deployments(int $team_id, int $project_id, int $limit = 20): array {
        $limit = max(1, min($limit, 100));

        $sql = <<<SQL
            SELECT d.*, (p.current_deployment_id = d.id) AS is_current
            FROM deployment d
            JOIN project p ON p.id = d.project_id
            WHERE d.project_id = :project_id AND d.team_id = :team_id
            ORDER BY d.id DESC
            LIMIT {$limit}
        SQL;
        return $this->db->query_bind($sql, ['project_id' => $project_id, 'team_id' => $team_id], 'object');
    }

    /**
     * A deployment (with its project's label and slug) if it belongs to the team.
     */
    function find_team_deployment(int $team_id, int $deployment_id): object|false {
        $sql = <<<SQL
            SELECT d.*, p.label AS project_label, p.slug AS project_slug
            FROM deployment d
            JOIN project p ON p.id = d.project_id
            WHERE d.id = :deployment_id AND d.team_id = :team_id
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, ['deployment_id' => $deployment_id, 'team_id' => $team_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * Log lines from the deployment's tasks after a given log id, oldest first.
     * Empty when the deployment is not the team's.
     */
    function deployment_logs(int $team_id, int $deployment_id, int $after_id = 0, int $limit = 200): array {
        $limit = max(1, min($limit, 1000));

        $sql = <<<SQL
            SELECT l.id, l.task_id, t.script, l.level, l.message, l.created_at
            FROM task_logs l
            JOIN tasks t ON t.id = l.task_id
            JOIN deployment d ON d.id = t.deployment_id
            WHERE d.id = :deployment_id
              AND d.team_id = :team_id
              AND l.id > :after_id
            ORDER BY l.id ASC
            LIMIT {$limit}
        SQL;
        return $this->db->query_bind($sql, [
            'deployment_id' => $deployment_id,
            'team_id' => $team_id,
            'after_id' => $after_id,
        ], 'object');
    }

    /**
     * A DNS-label safe, unique slug for a project label (max 40 chars).
     */
    function generate_project_slug(string $label, ?int $ignore_project_id = null): string {
        $base = strtolower(trim($label));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim(substr(trim($base, '-'), 0, 32), '-');

        if ($base === '' || in_array($base, self::RESERVED_SLUGS, true)) {
            $base = $base === '' ? 'app' : $base . '-app';
        }

        $slug = $base;
        $suffix = 2;
        while ($this->_slug_taken($slug, $ignore_project_id)) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    /**
     * The project's slug, generating and saving one if missing.
     */
    function ensure_project_slug(int $project_id): string {
        $rows = $this->db->query_bind(
            'SELECT id, label, slug FROM project WHERE id = :id LIMIT 1',
            ['id' => $project_id],
            'object'
        );
        $project = $rows[0] ?? throw new RuntimeException('Project not found');

        if (!empty($project->slug)) {
            return $project->slug;
        }

        $slug = $this->generate_project_slug($project->label, $project_id);
        $this->db->update($project_id, ['slug' => $slug], 'project');

        return $slug;
    }

    /**
     * The DNS TXT record that proves a customer controls the project's requested
     * domain: name "_trongate-cloud.{domain}", value an HMAC of project + domain.
     *
     * @return array{name: string, value: string}
     */
    function domain_verification_record(int $project_id, string $domain): array {
        if (APP_KEY === '') {
            throw new RuntimeException('APP_KEY is required to derive domain tokens');
        }

        return [
            'name' => '_trongate-cloud.' . $domain,
            'value' => 'trongate-cloud-verify=' . substr(hash_hmac('sha256', "domain:{$project_id}:{$domain}", APP_KEY), 0, 32),
        ];
    }

    /**
     * Check the TXT record and, when it matches, make the requested domain the
     * project's verified (routed) domain.
     *
     * @return bool|string true, or why it failed
     */
    function verify_project_domain(int $team_id, int $project_id): bool|string {
        $project = $this->_project_or_fail($team_id, $project_id);
        $domain = (string) ($project->domain ?? '');

        if ($domain === '') {
            return 'The project has no custom domain.';
        }

        if ($domain === (string) ($project->verified_domain ?? '')) {
            return true;
        }

        $record = $this->domain_verification_record($project_id, $domain);
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
            $this->db->update($project_id, ['verified_domain' => $domain], 'project');
        } catch (PDOException $e) {
            // unique_verified_domain: another project already serves it
            return 'This domain is already used by another project.';
        }

        return true;
    }

    /**
     * 64 random hex chars stored in project.webhook_secret. This is only a seed:
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
    function webhook_signing_secret(int $project_id, string $seed): string {
        if (APP_KEY === '') {
            throw new RuntimeException('APP_KEY is required to derive webhook secrets');
        }

        return hash_hmac('sha256', "webhook:{$project_id}:{$seed}", APP_KEY);
    }

    /**
     * The project's webhook signing secret, generating and saving a seed if missing.
     */
    function ensure_webhook_secret(int $team_id, int $project_id): string {
        $project = $this->_project_or_fail($team_id, $project_id);
        $seed = (string) ($project->webhook_secret ?? '');

        if ($seed === '') {
            $seed = $this->generate_webhook_secret();
            $this->db->update($project_id, ['webhook_secret' => $seed], 'project');
        }

        return $this->webhook_signing_secret($project_id, $seed);
    }

    function _project_or_fail(int $team_id, int $project_id): object {
        $project = $this->find_team_project($team_id, $project_id);

        if ($project === false) {
            throw new RuntimeException('Project not found');
        }

        return $project;
    }

    function _queue_task(int $team_id, ?int $project_id, ?int $deployment_id, string $script, array $payload): int {
        return $this->db->insert([
            'team_id' => $team_id,
            'project_id' => $project_id,
            'deployment_id' => $deployment_id,
            'script' => $script,
            'payload' => json_encode($payload),
        ], 'tasks');
    }

    /**
     * The project's deploy task that no worker has claimed yet, row-locked for
     * the surrounding transaction. A worker holding the row (FOR UPDATE SKIP LOCKED
     * in Runtime::pick_task) makes this wait, then return false once started.
     */
    function _queued_deploy(int $project_id): object|false {
        $sql = <<<SQL
            SELECT t.id AS task_id, d.id AS deployment_id
            FROM tasks t
            JOIN deployment d ON d.id = t.deployment_id
            WHERE t.project_id = :project_id
              AND t.script = 'deploy'
              AND t.started_at IS NULL
              AND t.completed_at IS NULL
              AND t.failed_at IS NULL
              AND d.status = 'pending'
            ORDER BY t.id DESC
            LIMIT 1
            FOR UPDATE
        SQL;
        $rows = $this->db->query_bind($sql, ['project_id' => $project_id], 'object');

        return $rows[0] ?? false;
    }

    function _previous_successful_deployment(object $project): object|false {
        $sql = <<<SQL
            SELECT d.*, p.label AS project_label, p.slug AS project_slug
            FROM deployment d
            JOIN project p ON p.id = d.project_id
            WHERE d.project_id = :project_id
              AND d.team_id = :team_id
              AND d.status IN ('success', 'rolled_back')
              AND d.image IS NOT NULL
              AND d.id <> :current_id
              AND d.id < :before_id
            ORDER BY d.id DESC
            LIMIT 1
        SQL;
        $current_id = (int) ($project->current_deployment_id ?? 0);
        $rows = $this->db->query_bind($sql, [
            'project_id' => (int) $project->id,
            'team_id' => (int) $project->team_id,
            'current_id' => $current_id,
            'before_id' => $current_id > 0 ? $current_id : PHP_INT_MAX,
        ], 'object');

        return $rows[0] ?? false;
    }

    function _slug_taken(string $slug, ?int $ignore_project_id): bool {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM project
            WHERE slug = :slug AND id <> :ignore_id
        SQL;
        $count = $this->db->query_bind($sql, ['slug' => $slug, 'ignore_id' => $ignore_project_id ?? 0], 'column');

        return (int) $count > 0;
    }
}
