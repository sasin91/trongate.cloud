<?php

require_once __DIR__ . '/../../platform/manifests/Manifests.php';
require_once __DIR__ . '/../../platform/live/App_resource_audit.php';

/**
 * MCP tools for controllers: definitions (tools/list) and handlers (tools/call).
 *
 * Admin scope: tools see every project. Handlers resolve the owning project of
 * an app or deployment first and pass that project id to Platform_actions.
 *
 * Add a tool by adding its name to TOOLS, a definition to _tool_definitions()
 * and a _tool_{name}(array $args): array method. Override any of them in the
 * controller to change behaviour.
 *
 * Requires the controller's model to be Mcp_model (Platform_actions + project lookups).
 */
trait Mcp_tools {

    const TOOLS = [
        'list_apps',
        'get_app',
        'list_deployments',
        'get_deployment_logs',
        'platform_status',
        'deploy_app',
        'rollback_app',
        'audit_app_resources',
        'retry_app_cleanup',
        'retry_project_cleanup',
    ];

    /** Deployment statuses after which nothing changes */
    const TERMINAL_STATUSES = ['success', 'failed', 'rolled_back'];

    function _tool_definitions(): array {
        $read_only = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $app_id = ['type' => 'integer', 'minimum' => 1, 'description' => 'App id (from list_apps)'];
        $project_filter = ['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional: only the apps of this project (a project groups apps in one namespace)']], 'additionalProperties' => false];

        return [
            [
                'name' => 'list_apps',
                'title' => 'List apps',
                'description' => 'List apps (deployed repositories) across all projects, or in one project, with their project, URLs and current deployment status.',
                'inputSchema' => $project_filter,
                'annotations' => $read_only,
            ],
            [
                'name' => 'get_app',
                'title' => 'Get app',
                'description' => 'Show one app: source repository, hostnames, settings and current deployment.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['app_id' => $app_id],
                    'required' => ['app_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => $read_only,
            ],
            [
                'name' => 'list_deployments',
                'title' => 'List deployments',
                'description' => "List an app's latest deployments, newest first.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'app_id' => $app_id,
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10, 'description' => 'How many deployments to return'],
                    ],
                    'required' => ['app_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => $read_only,
            ],
            [
                'name' => 'get_deployment_logs',
                'title' => 'Get deployment logs',
                'description' => 'Build and rollout log lines of a deployment, oldest first. Pass next_after_id from the previous call as after_id to follow a running deployment.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'deployment_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Deployment id'],
                        'after_id' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Only return log lines after this log id'],
                    ],
                    'required' => ['deployment_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => $read_only,
            ],
            [
                'name' => 'platform_status',
                'title' => 'Platform status',
                'description' => 'Platform overview (all projects, or one): apps and deployments by status, deployments in progress and recent failures.',
                'inputSchema' => $project_filter,
                'annotations' => $read_only,
            ],
            [
                'name' => 'deploy_app',
                'title' => 'Deploy app',
                'description' => "Build and deploy the app's configured branch (or a given commit) to production.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'app_id' => $app_id,
                        'commit_sha' => ['type' => 'string', 'pattern' => '^[0-9a-fA-F]{7,40}$', 'description' => 'Optional commit to deploy instead of the branch head'],
                    ],
                    'required' => ['app_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'rollback_app',
                'title' => 'Roll back app',
                'description' => 'Redeploy an earlier successful image. Without deployment_id, rolls back to the deployment before the current one.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'app_id' => $app_id,
                        'deployment_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional successful deployment to roll back to'],
                    ],
                    'required' => ['app_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'audit_app_resources',
                'title' => 'Audit app resources',
                'description' => "List cluster objects labelled as a trongate.cloud app that no longer match a live app in their project's namespace, deleted apps whose cleanup (destroy_app) failed or has not finished, and deleted projects whose namespace removal (destroy_project) failed or has not finished. Read-only: reports, never repairs.",
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'annotations' => $read_only,
            ],
            [
                'name' => 'retry_app_cleanup',
                'title' => 'Retry app cleanup',
                'description' => "Queue destroy_app again for a deleted app whose cleanup failed (see audit_app_resources). It removes the app's objects that carry the app's labels; the database and files are kept.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['app_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Deleted app id (from audit_app_resources)']],
                    'required' => ['app_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'retry_project_cleanup',
                'title' => 'Retry project cleanup',
                'description' => "Queue destroy_project again for a deleted project whose namespace is still there (see audit_app_resources). It deletes the namespace only if no app object is left in it.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Deleted project id (from audit_app_resources)']],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
        ];
    }

    /**
     * Run a tool. Returns structured data. Check _tool_exists() first: an
     * unknown tool is a protocol error, while exceptions thrown here (bad
     * arguments, not found) become tool results with isError.
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    function _call_tool(string $name, array $args): array {
        if (!$this->_tool_exists($name)) {
            throw new InvalidArgumentException("Unknown tool: {$name}");
        }

        $method = '_tool_' . $name;
        return $this->$method($args);
    }

    function _tool_exists(string $name): bool {
        return in_array($name, self::TOOLS, true) && method_exists($this, '_tool_' . $name);
    }

    function _tool_list_apps(array $args): array {
        $project_names = $this->model->project_names();
        $project_id = $this->_int_arg($args, 'project_id', false);

        if ($project_id > 0 && !isset($project_names[$project_id])) {
            throw new RuntimeException('Project not found');
        }

        $project_ids = $project_id > 0 ? [$project_id] : array_keys($project_names);

        $apps = [];
        foreach ($project_ids as $id) {
            foreach ($this->model->project_apps((int) $id) as $app) {
                $apps[] = $this->_app_summary($app) + ['project_id' => (int) $id, 'project' => $project_names[$id]];
            }
        }

        return ['apps' => $apps];
    }

    function _tool_get_app(array $args): array {
        [$project_id, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $summary = $this->_app_summary($app);

        $current = null;
        if (!empty($app->current_deployment_id)) {
            $deployment = $this->model->find_project_deployment($project_id, (int) $app->current_deployment_id);
            $current = $deployment === false ? null : ['is_current' => true] + $this->_deployment_summary($deployment);
        }

        if ($current !== null) {
            $summary['current_status'] = $current['status'];
            $summary['current_commit_sha'] = $current['commit_sha'];
            $summary['current_deployed_at'] = $current['completed_at'];
        }

        return $summary + [
            'persistent_paths' => Manifests::persistent_paths((array) $app),
            'php_extensions' => (string) ($app->php_extensions ?? ''),
            'webhook_configured' => !empty($app->webhook_secret),
            'current_deployment' => $current,
        ];
    }

    function _tool_list_deployments(array $args): array {
        [$project_id, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $limit = $this->_int_arg($args, 'limit', false, 10);

        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100');
        }

        $deployments = $this->model->app_deployments($project_id, (int) $app->id, $limit);

        return [
            'app' => ['id' => (int) $app->id, 'label' => $app->label],
            'deployments' => array_map(fn(object $deployment) => $this->_deployment_summary($deployment), $deployments),
        ];
    }

    function _tool_get_deployment_logs(array $args): array {
        $deployment_id = $this->_int_arg($args, 'deployment_id');
        $after_id = $this->_int_arg($args, 'after_id', false, 0);

        $project_id = $this->model->deployment_project_id($deployment_id);
        $deployment = $project_id === false ? false : $this->model->find_project_deployment($project_id, $deployment_id);
        if ($project_id === false || $deployment === false) {
            throw new RuntimeException('Deployment not found');
        }

        $lines = $this->model->deployment_logs($project_id, $deployment_id, max(0, $after_id), 200);
        $last = end($lines);

        return [
            'deployment' => $this->_deployment_summary($deployment) + ['app_label' => $deployment->app_label],
            'finished' => in_array($deployment->status, self::TERMINAL_STATUSES, true),
            'lines' => array_map(fn(object $line) => [
                'id' => (int) $line->id,
                'level' => $line->level,
                'step' => $line->script,
                'message' => $line->message,
                'at' => $line->created_at,
            ], $lines),
            'next_after_id' => $last === false ? max(0, $after_id) : (int) $last->id,
            'more' => count($lines) === 200,
        ];
    }

    function _tool_platform_status(array $args): array {
        $project_id = $this->_int_arg($args, 'project_id', false);
        $status = $this->model->platform_status($project_id > 0 ? $project_id : null);
        $counts = fn(array $rows) => array_combine(
            array_map(fn(object $row) => (string) $row->status, $rows),
            array_map(fn(object $row) => (int) $row->total, $rows)
        );

        return [
            'project' => $project_id > 0 ? ($this->model->project_names()[$project_id] ?? null) : 'all',
            'apps_total' => array_sum($counts($status['apps'])),
            'apps_by_status' => (object) $counts($status['apps']),
            'deployments_by_status' => (object) $counts($status['deployments']),
            'in_progress' => $status['in_progress'],
            'recent_failures' => $status['recent_failures'],
        ];
    }

    function _tool_deploy_app(array $args): array {
        [$project_id, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $commit_sha = $args['commit_sha'] ?? null;

        if ($commit_sha !== null && (!is_string($commit_sha) || !preg_match('/^[0-9a-f]{7,40}$/i', $commit_sha))) {
            throw new InvalidArgumentException('commit_sha must be 7 to 40 hex characters');
        }

        // Admin deploys have no project member or customer behind them
        $deployment_id = $this->model->queue_deploy($project_id, (int) $app->id, null, null, 'mcp', $commit_sha);

        return [
            'deployment_id' => $deployment_id,
            'status' => 'pending',
            'app' => ['id' => (int) $app->id, 'label' => $app->label],
            'next' => 'Follow progress with get_deployment_logs {"deployment_id": ' . $deployment_id . '}',
        ];
    }

    function _tool_rollback_app(array $args): array {
        [$project_id, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $target_id = isset($args['deployment_id']) ? $this->_int_arg($args, 'deployment_id') : null;

        $deployment_id = $this->model->queue_rollback($project_id, (int) $app->id, null, $target_id);

        return [
            'deployment_id' => $deployment_id,
            'status' => 'pending',
            'app' => ['id' => (int) $app->id, 'label' => $app->label],
            'next' => 'Follow progress with get_deployment_logs {"deployment_id": ' . $deployment_id . '}',
        ];
    }

    function _tool_audit_app_resources(array $args): array {
        try {
            $orphans = App_resource_audit::orphans(Kubernetes::from_env(), $this->model->live_app_refs());
        } catch (Client_Error $e) {
            throw new RuntimeException('Could not read the cluster: ' . $e->getMessage());
        }

        $cleanups = array_map(fn(object $row) => [
            'app_id' => (int) $row->app_id,
            'project_id' => (int) $row->project_id,
            'label' => $row->label,
            'slug' => $row->slug,
            'verified_domain' => $row->verified_domain,
            'deleted_at' => $row->deleted_at,
            'task_id' => $row->task_id === null ? null : (int) $row->task_id,
            'state' => match (true) {
                $row->task_id === null => 'never_queued',
                $row->failed_at !== null => 'failed',
                $row->started_at !== null => 'running',
                default => 'queued',
            },
            'error' => $row->error_message,
        ], $this->model->unfinished_cleanups());

        $project_cleanups = array_map(fn(object $row) => [
            'project_id' => (int) $row->project_id,
            'name' => $row->name,
            'namespace' => $row->namespace,
            'deleted_at' => $row->deleted_at,
            'task_id' => $row->task_id === null ? null : (int) $row->task_id,
            'state' => match (true) {
                $row->task_id === null => 'never_queued',
                $row->failed_at !== null => 'failed',
                $row->started_at !== null => 'running',
                default => 'queued',
            },
            'error' => $row->error_message,
        ], $this->model->unfinished_project_cleanups());

        return [
            'orphans' => $orphans,
            'unfinished_cleanups' => $cleanups,
            'unfinished_project_cleanups' => $project_cleanups,
            'checked_kinds' => array_column(App_resource_audit::KINDS, 1),
            'note' => 'Read-only: nothing was changed. Secrets are not listed (the web app cannot read them); a failed or never-queued cleanup may also have left the database Secret. Re-run an app cleanup with retry_app_cleanup {"app_id": ...} and a project cleanup with retry_project_cleanup {"project_id": ...}.',
        ];
    }

    function _tool_retry_app_cleanup(array $args): array {
        $app_id = $this->_int_arg($args, 'app_id');
        $project_id = $this->model->app_project_id($app_id);

        if ($project_id === false) {
            throw new RuntimeException('App not found');
        }

        $task_id = $this->model->requeue_app_destroy($project_id, $app_id);

        return [
            'task_id' => $task_id,
            'app_id' => $app_id,
            'next' => 'Check the result with audit_app_resources once the worker has run it',
        ];
    }

    function _tool_retry_project_cleanup(array $args): array {
        $project_id = $this->_int_arg($args, 'project_id');
        $task_id = $this->model->requeue_project_destroy($project_id);

        return [
            'task_id' => $task_id,
            'project_id' => $project_id,
            'next' => 'Check the result with audit_app_resources once the worker has run it',
        ];
    }

    /**
     * The app's project id and row.
     *
     * @return array{int, object}
     * @throws RuntimeException when the app doesn't exist
     */
    function _app_or_fail(int $app_id): array {
        $project_id = $this->model->app_project_id($app_id);
        $app = $project_id === false ? false : $this->model->find_project_app($project_id, $app_id);

        if ($project_id === false || $app === false) {
            throw new RuntimeException('App not found');
        }

        return [$project_id, $app];
    }

    /**
     * A positive (or zero for optional) integer argument; accepts numeric strings.
     *
     * @throws InvalidArgumentException when missing or not an integer
     */
    function _int_arg(array $args, string $key, bool $required = true, int $default = 0): int {
        if (!array_key_exists($key, $args) || $args[$key] === null) {
            if ($required) {
                throw new InvalidArgumentException("Missing required argument: {$key}");
            }
            return $default;
        }

        $value = $args[$key];
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < 0 || ($required && $value === 0)) {
            throw new InvalidArgumentException("Argument {$key} must be a positive integer");
        }

        return $value;
    }

    /**
     * Public app fields. Never includes webhook_secret.
     */
    function _app_summary(object $app): array {
        $hostnames = !empty($app->slug) ? Manifests::hostnames((array) $app) : [];

        return [
            'id' => (int) $app->id,
            'label' => $app->label,
            'slug' => $app->slug ?? null,
            'type' => $app->type ?? null,
            'urls' => array_map(fn(string $host) => 'https://' . $host, $hostnames),
            'source_url' => $app->source_url ?? null,
            'source_branch' => $app->source_branch ?? null,
            'always_on' => !empty($app->always_on),
            'resource_tier' => $app->resource_tier ?? Resource_tiers::DEFAULT_TIER,
            'current_deployment_id' => isset($app->current_deployment_id) ? (int) $app->current_deployment_id : null,
            'current_status' => $app->current_status ?? null,
            'current_commit_sha' => $app->current_commit_sha ?? null,
            'current_deployed_at' => $app->current_deployed_at ?? null,
        ];
    }

    function _deployment_summary(object $deployment): array {
        return [
            'id' => (int) $deployment->id,
            'app_id' => (int) $deployment->app_id,
            'status' => $deployment->status,
            'trigger' => $deployment->trigger_type ?? null,
            'commit_sha' => $deployment->commit_sha ?? null,
            'image' => $deployment->image ?? null,
            'is_current' => !empty($deployment->is_current),
            'created_at' => $deployment->created_at,
            'completed_at' => $deployment->completed_at ?? null,
        ];
    }
}
