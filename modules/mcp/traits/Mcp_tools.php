<?php

require_once __DIR__ . '/../../platform/manifests/Manifests.php';

/**
 * MCP tools for controllers: definitions (tools/list) and handlers (tools/call).
 *
 * Admin scope: tools see every team. Handlers resolve the owning team of a
 * project or deployment first and pass that team id to Platform_actions.
 *
 * Add a tool by adding its name to TOOLS, a definition to _tool_definitions()
 * and a _tool_{name}(array $args): array method. Override any of them in the
 * controller to change behaviour.
 *
 * Requires the controller's model to be Mcp_model (Platform_actions + team lookups).
 */
trait Mcp_tools {

    const TOOLS = [
        'list_projects',
        'get_project',
        'list_deployments',
        'get_deployment_logs',
        'platform_status',
        'deploy_project',
        'rollback_project',
    ];

    /** Deployment statuses after which nothing changes */
    const TERMINAL_STATUSES = ['success', 'failed', 'rolled_back'];

    function _tool_definitions(): array {
        $read_only = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $project_id = ['type' => 'integer', 'minimum' => 1, 'description' => 'Project id (from list_projects)'];
        $team_filter = ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional: only this team']], 'additionalProperties' => false];

        return [
            [
                'name' => 'list_projects',
                'title' => 'List projects',
                'description' => 'List projects across all teams (or one team) with their URLs and current deployment status.',
                'inputSchema' => $team_filter,
                'annotations' => $read_only,
            ],
            [
                'name' => 'get_project',
                'title' => 'Get project',
                'description' => 'Show one project: source repository, hostnames, settings and current deployment.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['project_id' => $project_id],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => $read_only,
            ],
            [
                'name' => 'list_deployments',
                'title' => 'List deployments',
                'description' => "List a project's latest deployments, newest first.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => $project_id,
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10, 'description' => 'How many deployments to return'],
                    ],
                    'required' => ['project_id'],
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
                'description' => 'Platform overview (all teams, or one): projects and deployments by status, deployments in progress and recent failures.',
                'inputSchema' => $team_filter,
                'annotations' => $read_only,
            ],
            [
                'name' => 'deploy_project',
                'title' => 'Deploy project',
                'description' => "Build and deploy the project's configured branch (or a given commit) to production.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => $project_id,
                        'commit_sha' => ['type' => 'string', 'pattern' => '^[0-9a-fA-F]{7,40}$', 'description' => 'Optional commit to deploy instead of the branch head'],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'rollback_project',
                'title' => 'Roll back project',
                'description' => 'Redeploy an earlier successful image. Without deployment_id, rolls back to the deployment before the current one.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => $project_id,
                        'deployment_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional successful deployment to roll back to'],
                    ],
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

    function _tool_list_projects(array $args): array {
        $team_names = $this->model->team_names();
        $team_id = $this->_int_arg($args, 'team_id', false);
        $team_ids = $team_id > 0 ? [$team_id] : array_keys($team_names);

        $projects = [];
        foreach ($team_ids as $id) {
            foreach ($this->model->team_projects((int) $id) as $project) {
                $projects[] = $this->_project_summary($project) + ['team' => $team_names[$id] ?? null];
            }
        }

        return ['projects' => $projects];
    }

    function _tool_get_project(array $args): array {
        [$team_id, $project] = $this->_project_or_fail($this->_int_arg($args, 'project_id'));
        $summary = $this->_project_summary($project);

        $current = null;
        if (!empty($project->current_deployment_id)) {
            $deployment = $this->model->find_team_deployment($team_id, (int) $project->current_deployment_id);
            $current = $deployment === false ? null : ['is_current' => true] + $this->_deployment_summary($deployment);
        }

        if ($current !== null) {
            $summary['current_status'] = $current['status'];
            $summary['current_commit_sha'] = $current['commit_sha'];
            $summary['current_deployed_at'] = $current['completed_at'];
        }

        return $summary + [
            'persistent_paths' => Manifests::persistent_paths((array) $project),
            'php_extensions' => (string) ($project->php_extensions ?? ''),
            'webhook_configured' => !empty($project->webhook_secret),
            'current_deployment' => $current,
        ];
    }

    function _tool_list_deployments(array $args): array {
        [$team_id, $project] = $this->_project_or_fail($this->_int_arg($args, 'project_id'));
        $limit = $this->_int_arg($args, 'limit', false, 10);

        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100');
        }

        $deployments = $this->model->project_deployments($team_id, (int) $project->id, $limit);

        return [
            'project' => ['id' => (int) $project->id, 'label' => $project->label],
            'deployments' => array_map(fn(object $deployment) => $this->_deployment_summary($deployment), $deployments),
        ];
    }

    function _tool_get_deployment_logs(array $args): array {
        $deployment_id = $this->_int_arg($args, 'deployment_id');
        $after_id = $this->_int_arg($args, 'after_id', false, 0);

        $team_id = $this->model->deployment_team_id($deployment_id);
        $deployment = $team_id === false ? false : $this->model->find_team_deployment($team_id, $deployment_id);
        if ($team_id === false || $deployment === false) {
            throw new RuntimeException('Deployment not found');
        }

        $lines = $this->model->deployment_logs($team_id, $deployment_id, max(0, $after_id), 200);
        $last = end($lines);

        return [
            'deployment' => $this->_deployment_summary($deployment) + ['project_label' => $deployment->project_label],
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
        $team_id = $this->_int_arg($args, 'team_id', false);
        $status = $this->model->platform_status($team_id > 0 ? $team_id : null);
        $counts = fn(array $rows) => array_combine(
            array_map(fn(object $row) => (string) $row->status, $rows),
            array_map(fn(object $row) => (int) $row->total, $rows)
        );

        return [
            'team' => $team_id > 0 ? ($this->model->team_names()[$team_id] ?? null) : 'all',
            'projects_total' => array_sum($counts($status['projects'])),
            'projects_by_status' => (object) $counts($status['projects']),
            'deployments_by_status' => (object) $counts($status['deployments']),
            'in_progress' => $status['in_progress'],
            'recent_failures' => $status['recent_failures'],
        ];
    }

    function _tool_deploy_project(array $args): array {
        [$team_id, $project] = $this->_project_or_fail($this->_int_arg($args, 'project_id'));
        $commit_sha = $args['commit_sha'] ?? null;

        if ($commit_sha !== null && (!is_string($commit_sha) || !preg_match('/^[0-9a-f]{7,40}$/i', $commit_sha))) {
            throw new InvalidArgumentException('commit_sha must be 7 to 40 hex characters');
        }

        // Admin deploys have no team member or customer behind them
        $deployment_id = $this->model->queue_deploy($team_id, (int) $project->id, null, null, 'mcp', $commit_sha);

        return [
            'deployment_id' => $deployment_id,
            'status' => 'pending',
            'project' => ['id' => (int) $project->id, 'label' => $project->label],
            'next' => 'Follow progress with get_deployment_logs {"deployment_id": ' . $deployment_id . '}',
        ];
    }

    function _tool_rollback_project(array $args): array {
        [$team_id, $project] = $this->_project_or_fail($this->_int_arg($args, 'project_id'));
        $target_id = isset($args['deployment_id']) ? $this->_int_arg($args, 'deployment_id') : null;

        $deployment_id = $this->model->queue_rollback($team_id, (int) $project->id, null, $target_id);

        return [
            'deployment_id' => $deployment_id,
            'status' => 'pending',
            'project' => ['id' => (int) $project->id, 'label' => $project->label],
            'next' => 'Follow progress with get_deployment_logs {"deployment_id": ' . $deployment_id . '}',
        ];
    }

    /**
     * The project's team id and row.
     *
     * @return array{int, object}
     * @throws RuntimeException when the project doesn't exist
     */
    function _project_or_fail(int $project_id): array {
        $team_id = $this->model->project_team_id($project_id);
        $project = $team_id === false ? false : $this->model->find_team_project($team_id, $project_id);

        if ($team_id === false || $project === false) {
            throw new RuntimeException('Project not found');
        }

        return [$team_id, $project];
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
     * Public project fields. Never includes webhook_secret.
     */
    function _project_summary(object $project): array {
        $hostnames = !empty($project->slug) ? Manifests::hostnames((array) $project) : [];

        return [
            'id' => (int) $project->id,
            'label' => $project->label,
            'slug' => $project->slug ?? null,
            'type' => $project->type ?? null,
            'urls' => array_map(fn(string $host) => 'https://' . $host, $hostnames),
            'source_url' => $project->source_url ?? null,
            'source_branch' => $project->source_branch ?? null,
            'always_on' => !empty($project->always_on),
            'resource_tier' => $project->resource_tier ?? Resource_tiers::DEFAULT_TIER,
            'current_deployment_id' => isset($project->current_deployment_id) ? (int) $project->current_deployment_id : null,
            'current_status' => $project->current_status ?? null,
            'current_commit_sha' => $project->current_commit_sha ?? null,
            'current_deployed_at' => $project->current_deployed_at ?? null,
        ];
    }

    function _deployment_summary(object $deployment): array {
        return [
            'id' => (int) $deployment->id,
            'project_id' => (int) $deployment->project_id,
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
