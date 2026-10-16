<?php

require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once APPPATH . 'modules/platform/manifests/App_env.php';
require_once APPPATH . 'modules/platform/manifests/Project_service.php';
require_once APPPATH . 'modules/platform/mail/Mail_capture.php';
require_once APPPATH . 'modules/platform/traits/Live_app_state.php';
require_once APPPATH . 'modules/services/traits/Provider_clients.php';
require_once APPPATH . 'modules/apps/traits/App_rules.php';
require_once APPPATH . 'modules/services/clients/Client.php';
require_once APPPATH . 'modules/services/clients/Github.php';

/**
 * MCP tools for controllers: the registry (tools/list, tools/call) and the
 * tools for projects, apps, deployments, logs and environment variables.
 * Mcp_database_tools and Mcp_admin_tools add their own groups.
 *
 * Every definition lists the scopes it is offered in: 'customer' (an OAuth
 * access token: the tools act as that customer, in the projects they are a
 * member of, like the control panel) and/or 'admin' (MCP_SECRET: every project).
 *
 * Add a tool with a definition in its group's _..._tools() and a
 * _tool_{name}(array $args): array method. Throw InvalidArgumentException or
 * RuntimeException for anything the caller should read (bad arguments, not found).
 *
 * The controller provides _is_admin() and $customer, and its model is Mcp_model.
 */
trait Mcp_tools {
    use Live_app_state;
    use Provider_clients;
    use App_rules;

    /** Deployment statuses after which nothing changes */
    const TERMINAL_STATUSES = ['success', 'failed', 'rolled_back'];

    // --- Registry ---

    /**
     * The tools offered to this request's caller, as tools/list returns them
     */
    function _tool_definitions(): array {
        $scope = $this->_is_admin() ? 'admin' : 'customer';
        $tools = [];

        foreach ([...$this->_app_tools(), ...$this->_database_tools(), ...$this->_admin_tools()] as $tool) {
            if (in_array($scope, $tool['scopes'], true)) {
                unset($tool['scopes']);
                $tools[] = $tool;
            }
        }

        return $tools;
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
        return in_array($name, array_column($this->_tool_definitions(), 'name'), true)
            && method_exists($this, '_tool_' . $name);
    }

    /**
     * MCP tool annotations
     */
    function _hints(bool $read_only, bool $destructive = false, bool $idempotent = false): array {
        return [
            'readOnlyHint' => $read_only,
            'destructiveHint' => $destructive,
            'idempotentHint' => $read_only || $idempotent,
            'openWorldHint' => false,
        ];
    }

    /**
     * An input schema: properties, the required ones, nothing else
     */
    function _schema(array $properties = [], array $required = []): array {
        return array_filter([
            'type' => 'object',
            'properties' => $properties === [] ? (object) [] : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ], fn($value) => $value !== []);
    }

    // --- Projects, apps, deployments, logs, environment variables ---

    function _app_tools(): array {
        $app_id = ['type' => 'integer', 'minimum' => 1, 'description' => 'App id (from list_apps)'];
        $project_id = ['type' => 'integer', 'minimum' => 1, 'description' => 'Project id (from list_projects)'];
        $service_id = ['type' => 'integer', 'minimum' => 1, 'description' => 'Service id (from list_services)'];
        $both = ['customer', 'admin'];

        return [
            [
                'name' => 'list_projects',
                'title' => 'List projects',
                'description' => 'Your projects, with your role (owner or member) and how many apps each holds. A project groups apps that can reach each other.',
                'inputSchema' => $this->_schema(),
                'annotations' => $this->_hints(true),
                'scopes' => ['customer'],
            ],
            [
                'name' => 'create_project',
                'title' => 'Create project',
                'description' => 'Create a project, owned by you, to put apps in.',
                'inputSchema' => $this->_schema(['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]], ['name']),
                'annotations' => $this->_hints(false),
                'scopes' => ['customer'],
            ],
            [
                'name' => 'list_apps',
                'title' => 'List apps',
                'description' => 'List apps (deployed repositories), in all projects or one, with their URLs and current deployment status.',
                'inputSchema' => $this->_schema(['project_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional: only the apps of this project']]),
                'annotations' => $this->_hints(true),
                'scopes' => $both,
            ],
            [
                'name' => 'get_app',
                'title' => 'Get app',
                'description' => 'One app: source repository and branch, URLs, custom domain and its DNS status, settings, whether it is running or asleep, current deployment, database name and environment variable names.',
                'inputSchema' => $this->_schema(['app_id' => $app_id], ['app_id']),
                'annotations' => $this->_hints(true),
                'scopes' => $both,
            ],
            [
                'name' => 'create_app',
                'title' => 'Create app',
                'description' => 'Add an app to a project from a GitHub repository the trongate.cloud GitHub App can read, and deploy it. Each app gets its own database and {slug}.' . PLATFORM_APPS_DOMAIN . ' address.',
                'inputSchema' => $this->_schema([
                    'project_id' => ['type' => 'integer', 'minimum' => 1],
                    'repository' => ['type' => 'string', 'description' => 'owner/name on GitHub, or a link to a public GitHub repository (read without the GitHub App; it deploys only when asked, not on push)'],
                    'label' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 50, 'description' => 'App name, also used for its address'],
                    'branch' => ['type' => 'string', 'default' => 'main'],
                    'type' => ['type' => 'string', 'enum' => array_keys(self::APP_TYPES), 'default' => 'trongate_v2'],
                    'domain' => ['type' => 'string', 'description' => 'Optional custom domain, verified later with a DNS TXT record'],
                    'auto_deploy' => ['type' => 'boolean', 'default' => true, 'description' => 'Deploy on every push to the branch'],
                    'deploy' => ['type' => 'boolean', 'default' => true, 'description' => 'Deploy it now'],
                ], ['project_id', 'repository', 'label']),
                'annotations' => $this->_hints(false),
                'scopes' => ['customer'],
            ],
            [
                'name' => 'update_app_settings',
                'title' => 'Update app settings',
                'description' => "Change an app's settings. Only the fields you pass change. They take effect on the next deploy.",
                'inputSchema' => $this->_schema([
                    'app_id' => $app_id,
                    'domain' => ['type' => ['string', 'null'], 'description' => 'Custom domain; null or "" removes it'],
                    'persistent_paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => "Directories (relative to the app's root) kept across deploys, e.g. modules/blog/uploads"],
                    'php_extensions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Extra PHP extensions'],
                    'build_commands' => ['type' => 'string', 'description' => 'Shell commands, one per line, run in the image build after composer install, e.g. "npm ci\nnpm run build"; Node (npm, pnpm, yarn) and Bun are added when a command uses them; "" runs none'],
                    'processes' => ['type' => 'array', 'description' => 'The full list of processes run next to the app, which never sleep, e.g. [{"name": "worker", "command": "php bin/queue.php work --time-limit=3600", "replicas": 1}]; [] removes them all. Replicas run side by side.', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => 'Lowercase letters, numbers and -, at most 20'], 'command' => ['type' => 'string'], 'replicas' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Manifests::MAX_PROCESS_REPLICAS]], 'required' => ['name', 'command']]],
                    'always_on' => ['type' => 'boolean', 'description' => 'Never sleep when idle (needed when other apps call it directly)'],
                    'auto_deploy' => ['type' => 'boolean', 'description' => 'Deploy on every push to the branch'],
                    'source_branch' => ['type' => 'string', 'description' => 'The branch it deploys from (a staging app: staging by default)'],
                    'trongate_env' => ['type' => 'string', 'description' => "Trongate's ENV constant in config/config.php, e.g. " . implode(', ', Runtime_settings::TRONGATE_ENV_SUGGESTIONS) . ' (dev shows errors)'],
                    'default_module' => ['type' => 'string', 'description' => "Trongate's DEFAULT_MODULE in config/config.php, the module the app opens on, e.g. welcome; \"\" keeps the app's own"],
                    'captures_mail' => ['type' => 'boolean', 'description' => "Catch mail sent with Trongate's email module on the app's Mail tab instead of delivering it (on by default); off sends with the app's own config/trongate_email.php"],
                    'health_check_path' => ['type' => 'string', 'description' => 'Path a deploy must answer 2xx/3xx on before it goes live, e.g. /health for an API whose home page is a 404; "" or "/" is the home page'],
                ], ['app_id']),
                'annotations' => $this->_hints(false, true, true),
                'scopes' => ['customer'],
            ],
            [
                'name' => 'verify_domain',
                'title' => 'Verify custom domain',
                'description' => "Check the custom domain's TXT record (see get_app for the record to add). Once verified it goes live when its DNS points at trongate.cloud.",
                'inputSchema' => $this->_schema(['app_id' => $app_id], ['app_id']),
                'annotations' => $this->_hints(false, false, true),
                'scopes' => ['customer'],
            ],
            [
                'name' => 'list_deployments',
                'title' => 'List deployments',
                'description' => "An app's latest deployments, newest first.",
                'inputSchema' => $this->_schema([
                    'app_id' => $app_id,
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
                ], ['app_id']),
                'annotations' => $this->_hints(true),
                'scopes' => $both,
            ],
            [
                'name' => 'get_deployment_logs',
                'title' => 'Get deployment logs',
                'description' => 'Build and rollout log of a deployment, oldest first. Pass next_after_id from the previous call as after_id to follow a running deployment.',
                'inputSchema' => $this->_schema([
                    'deployment_id' => ['type' => 'integer', 'minimum' => 1],
                    'after_id' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Only lines after this log id'],
                ], ['deployment_id']),
                'annotations' => $this->_hints(true),
                'scopes' => $both,
            ],
            [
                'name' => 'deploy_app',
                'title' => 'Deploy app',
                'description' => "Build and deploy the app's branch (or a given commit) to production.",
                'inputSchema' => $this->_schema([
                    'app_id' => $app_id,
                    'commit_sha' => ['type' => 'string', 'pattern' => '^[0-9a-fA-F]{7,40}$', 'description' => 'Optional commit instead of the branch head'],
                ], ['app_id']),
                'annotations' => $this->_hints(false, true),
                'scopes' => $both,
            ],
            [
                'name' => 'rollback_app',
                'title' => 'Roll back app',
                'description' => 'Redeploy an earlier successful build. Without deployment_id, the one before the current deployment.',
                'inputSchema' => $this->_schema([
                    'app_id' => $app_id,
                    'deployment_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional successful deployment to roll back to'],
                ], ['app_id']),
                'annotations' => $this->_hints(false, true),
                'scopes' => $both,
            ],
            [
                'name' => 'upgrade_trongate',
                'title' => 'Upgrade Trongate',
                'description' => "Open a pull request on the app's repository that moves it to the newest Trongate v2 release. Framework files the app changed are merged, config values are kept, and new settings are added. Nothing deploys until the pull request is merged. The result shows in get_app as trongate_upgrade.",
                'inputSchema' => $this->_schema(['app_id' => $app_id], ['app_id']),
                'annotations' => $this->_hints(false, false, true),
                'scopes' => $both,
            ],
            [
                'name' => 'get_app_logs',
                'title' => 'Get app logs',
                'description' => "The running app's own output (PHP errors, web server lines), oldest first, with a level per line. An asleep app has no logs; reading never wakes it. Pass next_since from the previous call as since to get only newer lines.",
                'inputSchema' => $this->_schema([
                    'app_id' => $app_id,
                    'since' => ['type' => 'string', 'description' => 'next_since from the previous call'],
                    'problems_only' => ['type' => 'boolean', 'default' => false, 'description' => 'Only warnings and errors'],
                ], ['app_id']),
                'annotations' => $this->_hints(true),
                'scopes' => $both,
            ],
            [
                'name' => 'list_services',
                'title' => 'List services',
                'description' => "A project's services: images that run next to its apps, always on, reachable by them at {NAME}_URL. With each one's image, port, memory and state.",
                'inputSchema' => $this->_schema(['project_id' => $project_id], ['project_id']),
                'annotations' => $this->_hints(true),
                'scopes' => ['customer'],
            ],
            [
                'name' => 'add_service',
                'title' => 'Add service',
                'description' => 'Run a container image next to a project\'s apps. They get {NAME}_URL (http://s{id}:{port}) on their next deploy. The container runs without root, gets PORT, and is reachable only from the project.',
                'inputSchema' => $this->_schema([
                    'project_id' => $project_id,
                    'name' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,39}$', 'description' => 'e.g. search; the apps get SEARCH_URL'],
                    'image' => ['type' => 'string', 'maxLength' => Project_service::MAX_IMAGE_LENGTH, 'description' => 'With a version, e.g. ghcr.io/owner/name:1.2.3 (not :latest)'],
                    'port' => ['type' => 'integer', 'minimum' => 1024, 'maximum' => 65535, 'description' => 'Where it listens (default ' . Project_service::DEFAULT_PORT . ')'],
                    'memory_mb' => ['type' => 'integer', 'minimum' => Project_service::MIN_MEMORY_MB, 'maximum' => Project_service::MAX_MEMORY_MB, 'description' => 'Memory request and limit (default ' . Project_service::DEFAULT_MEMORY_MB . ')'],
                    'env' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Environment variables, write-only'],
                ], ['project_id', 'name', 'image']),
                'annotations' => $this->_hints(false),
                'scopes' => ['customer'],
            ],
            [
                'name' => 'redeploy_service',
                'title' => 'Redeploy service',
                'description' => 'Apply a service again, e.g. after it crashed.',
                'inputSchema' => $this->_schema(['project_id' => $project_id, 'service_id' => $service_id], ['project_id', 'service_id']),
                'annotations' => $this->_hints(false, false, true),
                'scopes' => ['customer'],
            ],
            [
                'name' => 'delete_service',
                'title' => 'Delete service',
                'description' => 'Stop and remove a service and its environment variables. Apps keep its URL until their next deploy.',
                'inputSchema' => $this->_schema(['project_id' => $project_id, 'service_id' => $service_id], ['project_id', 'service_id']),
                'annotations' => $this->_hints(false, true, true),
                'scopes' => ['customer'],
            ],
            [
                'name' => 'list_env_vars',
                'title' => 'List environment variables',
                'description' => "Names of the app's environment variables. Values are write-only and never shown.",
                'inputSchema' => $this->_schema(['app_id' => $app_id], ['app_id']),
                'annotations' => $this->_hints(true),
                'scopes' => ['customer'],
            ],
            [
                'name' => 'set_env_var',
                'title' => 'Set environment variable',
                'description' => 'Add or replace an environment variable. It takes effect on the next deploy.',
                'inputSchema' => $this->_schema([
                    'app_id' => $app_id,
                    'name' => ['type' => 'string', 'pattern' => '^[A-Za-z_][A-Za-z0-9_]*$', 'maxLength' => App_env::MAX_NAME_LENGTH],
                    'value' => ['type' => 'string', 'minLength' => 1],
                ], ['app_id', 'name', 'value']),
                'annotations' => $this->_hints(false, true, true),
                'scopes' => ['customer'],
            ],
            [
                'name' => 'delete_env_var',
                'title' => 'Delete environment variable',
                'description' => 'Remove an environment variable. It is gone from the app after the next deploy.',
                'inputSchema' => $this->_schema(['app_id' => $app_id, 'name' => ['type' => 'string']], ['app_id', 'name']),
                'annotations' => $this->_hints(false, true, true),
                'scopes' => ['customer'],
            ],
        ];
    }

    function _tool_list_projects(array $args): array {
        return [
            'projects' => array_map(fn(object $project) => [
                'id' => (int) $project->id,
                'name' => $project->name,
                'role' => $project->member_role,
                'app_count' => (int) $project->app_count,
                'created_at' => $project->created_at,
            ], $this->model->member_projects((int) $this->customer->id)),
        ];
    }

    function _tool_create_project(array $args): array {
        $project_id = $this->model->create_project((int) $this->customer->id, $this->_string_arg($args, 'name'));
        $project = $this->model->find_member_project((int) $this->customer->id, $project_id);

        return [
            'project' => ['id' => $project_id, 'name' => $project === false ? null : $project->name, 'role' => 'owner'],
            'next' => 'Add an app with create_app {"project_id": ' . $project_id . ', ...}',
        ];
    }

    function _tool_list_apps(array $args): array {
        $project_id = $this->_int_arg($args, 'project_id', false);

        $project_names = $this->_is_admin()
            ? $this->model->project_names()
            : array_column($this->model->member_projects((int) $this->customer->id), 'name', 'id');

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

        $summary += [
            'project_id' => $project_id,
            'live_state' => $this->_live_app_state($app),
            'custom_domain' => $this->_domain_status($app),
            'persistent_paths' => Runtime_settings::persistent_paths((array) $app),
            'php_extensions' => Runtime_settings::php_extensions((array) $app),
            'build_commands' => Runtime_settings::build_commands((array) $app),
            'processes' => $this->_processes_with_state($app),
            'auto_deploy' => (bool) ($app->auto_deploy ?? false),
            'trongate_env' => Runtime_settings::trongate_env((array) $app),
            'default_module' => Runtime_settings::default_module((array) $app),
            'captures_mail' => Runtime_settings::captures_mail((array) $app),
            'health_check_path' => Runtime_settings::health_check_path((array) $app),
            'database' => empty($app->database_server_id) ? null : Manifests::database_name((int) $app->id),
            'current_deployment' => $current,
            'trongate_upgrade' => $this->_upgrade_summary($this->model->latest_upgrade((int) $app->id)),
        ];

        if (!$this->_is_admin()) {
            $summary['env_var_names'] = array_keys($this->_app_env($app));
            $summary['role'] = $app->member_role;
        }

        return $summary;
    }

    /**
     * The app's processes, each with its live_state (null when the cluster
     * cannot be read, not_running when the next deploy has yet to start it)
     *
     * @return list<array{name: string, command: string, replicas: int, live_state: array{state: string, label: string, detail: string}|null}>
     */
    function _processes_with_state(object $app): array {
        $states = $this->_live_process_states($app);

        return array_map(fn(array $process) => $process + [
            'live_state' => $states === null ? null : ($states[$process['name']] ?? App_state::_make('not_running', 'Starts on the next deploy')),
        ], $this->model->app_processes((int) $app->id));
    }

    /**
     * Where a new app comes from: a link to a public repository is read
     * without the GitHub App; owner/name must be one the customer's GitHub
     * sign-in can pick.
     *
     * @return array{url: string, public: bool}
     */
    function _new_app_source(int $customer_id, string $repository): array {
        $public_repository = Github::repository_from_link($repository);

        if ($public_repository !== null) {
            return ['url' => $this->_public_repository_url($public_repository), 'public' => true];
        }

        $this->_check($this->_validate_repository($repository));
        $provider = $this->_customer_provider($customer_id, 'source');

        if ($provider === false) {
            throw new RuntimeException('Connect GitHub first: sign in to ' . BASE_URL . ' and press "Connect GitHub" when adding an app. Or pass a link to a public GitHub repository.');
        }

        try {
            $client = $this->_provider_client($customer_id, $provider);
            $pickable = $client->can_pick($repository);
        } catch (Client_Error $e) {
            throw new RuntimeException('Could not reach GitHub to check the repository. Try again.');
        }

        if (!$pickable) {
            throw new RuntimeException('The trongate.cloud GitHub App cannot read ' . $repository . '. Install it on that repository first: ' . $this->services->github_app()->install_url());
        }

        return ['url' => $client->url($repository), 'public' => false];
    }

    /**
     * https://github.com/owner/name of a repository that must be public
     */
    function _public_repository_url(string $repository): string {
        $github = Github::anonymous();

        try {
            $public = $github->is_public($repository);
        } catch (Client_Error $e) {
            if ($e->getCode() === 404) {
                throw new RuntimeException('There is no public repository at github.com/' . $repository . '.');
            }

            error_log("MCP create_app: could not check the public link to {$repository}: {$e->getMessage()}");
            throw new RuntimeException('Could not reach GitHub to check the repository. Try again.');
        }

        if (!$public) {
            throw new RuntimeException($repository . ' is private. Install the trongate.cloud GitHub App on it and pass owner/name instead.');
        }

        return $github->url($repository);
    }

    function _tool_create_app(array $args): array {
        $customer_id = (int) $this->customer->id;
        $project = $this->model->find_member_project($customer_id, $this->_int_arg($args, 'project_id'));

        if ($project === false) {
            throw new RuntimeException('Project not found');
        }

        $repository = $this->_string_arg($args, 'repository');
        $branch = $this->_string_arg($args, 'branch', false, 'main');
        $label = $this->_string_arg($args, 'label');
        $type = $this->_string_arg($args, 'type', false, 'trongate_v2');
        $domain = $this->_string_arg($args, 'domain', false);

        if (mb_strlen($label) < 3 || mb_strlen($label) > 50) {
            throw new InvalidArgumentException('label must be 3 to 50 characters');
        }

        $this->_check($this->_validate_branch($branch));
        $this->_check($this->_validate_app_type($type));
        $this->_check($this->_validate_domain($domain));

        $source = $this->_new_app_source($customer_id, $repository);
        $auto_deploy = $this->_bool_arg($args, 'auto_deploy', true);

        // No webhook arrives for a repository the GitHub App is not installed on
        if ($source['public']) {
            $auto_deploy = false;
        }

        $app_id = $this->model->create_app(
            project_id: (int) $project->id,
            label: $label,
            type: $type,
            source_url: $source['url'],
            source_provider: 'github',
            source_branch: $branch,
            domain: $this->_normalise_domain($domain),
            auto_deploy: $auto_deploy,
        );

        if ($app_id === false) {
            throw new RuntimeException('The app could not be created. Try again.');
        }

        $deployment_id = null;
        if ($this->_bool_arg($args, 'deploy', true)) {
            $deployment_id = $this->model->queue_deploy((int) $project->id, (int) $app_id, $customer_id, 'mcp');
        }

        $app = $this->model->find_member_app($customer_id, (int) $app_id);

        return [
            'app' => $this->_app_summary($app),
            'deployment_id' => $deployment_id,
            'next' => $deployment_id === null
                ? 'Deploy it with deploy_app {"app_id": ' . $app_id . '}'
                : 'Follow progress with get_deployment_logs {"deployment_id": ' . $deployment_id . '}',
        ];
    }

    function _tool_update_app_settings(array $args): array {
        [$project_id, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $this->editing_app_id = (int) $app->id;

        $settings = [
            'domain' => $this->_normalise_domain((string) ($app->domain ?? '')),
            'persistent_paths' => Runtime_settings::persistent_paths((array) $app),
            'php_extensions' => Runtime_settings::php_extensions((array) $app),
            'build_commands' => Runtime_settings::build_commands((array) $app),
            'always_on' => Runtime_settings::always_on((array) $app),
            'auto_deploy' => (bool) $app->auto_deploy,
            'source_branch' => (string) $app->source_branch,
            'trongate_env' => Runtime_settings::trongate_env((array) $app),
            'default_module' => Runtime_settings::default_module((array) $app),
            'captures_mail' => Runtime_settings::captures_mail((array) $app),
            'health_check_path' => Runtime_settings::health_check_path((array) $app),
        ];
        $processes = null;
        $changed = [];

        if (array_key_exists('domain', $args)) {
            $domain = $args['domain'] === null ? '' : $this->_string_arg($args, 'domain', false);
            $this->_check($this->_validate_domain($domain));
            $settings['domain'] = $this->_normalise_domain($domain);
            $changed[] = 'domain';
        }

        if (array_key_exists('persistent_paths', $args)) {
            $paths = implode("\n", $this->_strings_arg($args, 'persistent_paths'));
            $this->_check($this->_validate_persistent_paths($paths));
            $settings['persistent_paths'] = $this->_parse_persistent_paths($paths);
            $changed[] = 'persistent_paths';
        }

        if (array_key_exists('php_extensions', $args)) {
            $extensions = implode(',', $this->_strings_arg($args, 'php_extensions'));
            $this->_check($this->_validate_php_extensions($extensions));
            $settings['php_extensions'] = $this->_parse_php_extensions($extensions);
            $changed[] = 'php_extensions';
        }

        if (array_key_exists('build_commands', $args)) {
            $commands = $this->_string_arg($args, 'build_commands', false);
            $this->_check($this->_validate_build_commands($commands));
            $settings['build_commands'] = Build_commands::normalise($commands);
            $changed[] = 'build_commands';
        }

        if (array_key_exists('processes', $args)) {
            if (!is_array($args['processes']) || !array_is_list($args['processes'])) {
                throw new InvalidArgumentException('processes must be a list of {name, command, replicas}');
            }
            $processes = [];
            foreach ($args['processes'] as $process) {
                if (!is_array($process) || !is_string($process['name'] ?? null) || !is_string($process['command'] ?? null)) {
                    throw new InvalidArgumentException('Each process needs a name and a command');
                }
                $processes[] = [
                    'name' => strtolower(trim($process['name'])),
                    'command' => trim($process['command']),
                    'replicas' => (int) ($process['replicas'] ?? 1),
                ];
            }
            $this->_check($this->_processes_error($processes) ?? true);
            $changed[] = 'processes';
        }

        foreach (['always_on', 'auto_deploy', 'captures_mail'] as $flag) {
            if (array_key_exists($flag, $args)) {
                $settings[$flag] = $this->_bool_arg($args, $flag);
                $changed[] = $flag;
            }
        }

        if (array_key_exists('source_branch', $args)) {
            $branch = trim($this->_string_arg($args, 'source_branch'));
            $this->_check($this->_validate_branch($branch));
            $settings['source_branch'] = $branch;
            $changed[] = 'source_branch';
        }

        if (array_key_exists('trongate_env', $args)) {
            $env = $this->_string_arg($args, 'trongate_env');
            $this->_check($this->_validate_trongate_env($env));
            $settings['trongate_env'] = trim($env);
            $changed[] = 'trongate_env';
        }

        if (array_key_exists('default_module', $args)) {
            $module = $this->_string_arg($args, 'default_module', false);
            $this->_check($this->_validate_default_module($module));
            $settings['default_module'] = trim($module);
            $changed[] = 'default_module';
        }

        if (array_key_exists('health_check_path', $args)) {
            $path = $this->_string_arg($args, 'health_check_path', false);
            $this->_check($this->_validate_health_check_path($path));
            $settings['health_check_path'] = $this->_parse_health_check_path($path);
            $changed[] = 'health_check_path';
        }

        if ($changed === []) {
            throw new InvalidArgumentException('Pass at least one setting to change');
        }

        $this->model->update_app_settings($project_id, (int) $app->id, $settings);
        if ($processes !== null) {
            $this->model->save_app_processes((int) $app->id, $processes);
        }
        [, $app] = $this->_app_or_fail((int) $app->id);

        return [
            'changed' => $changed,
            'settings' => [
                'domain' => $this->_domain_status($app),
                'persistent_paths' => Runtime_settings::persistent_paths((array) $app),
                'php_extensions' => Runtime_settings::php_extensions((array) $app),
                'build_commands' => Runtime_settings::build_commands((array) $app),
                'processes' => $this->model->app_processes((int) $app->id),
                'always_on' => Runtime_settings::always_on((array) $app),
                'auto_deploy' => (bool) $app->auto_deploy,
                'trongate_env' => Runtime_settings::trongate_env((array) $app),
                'default_module' => Runtime_settings::default_module((array) $app),
                'captures_mail' => Runtime_settings::captures_mail((array) $app),
            'health_check_path' => Runtime_settings::health_check_path((array) $app),
            ],
            'next' => 'Settings take effect on the next deploy: deploy_app {"app_id": ' . $app->id . '}',
        ];
    }

    function _tool_verify_domain(array $args): array {
        [$project_id, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $result = $this->model->verify_app_domain($project_id, (int) $app->id);

        if ($result !== true) {
            throw new RuntimeException((string) $result);
        }

        [, $app] = $this->_app_or_fail((int) $app->id);

        return [
            'verified' => true,
            'custom_domain' => $this->_domain_status($app),
            'next' => 'It goes live by itself once its DNS points at trongate.cloud; get_app shows when.',
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

        if ($this->_is_admin()) {
            $project_id = $this->model->deployment_project_id($deployment_id);
            $deployment = $project_id === false ? false : $this->model->find_project_deployment($project_id, $deployment_id);
        } else {
            $deployment = $this->model->find_member_deployment((int) $this->customer->id, $deployment_id);
            $project_id = $deployment === false ? false : (int) $deployment->project_id;
        }

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

    function _tool_deploy_app(array $args): array {
        [$project_id, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $commit_sha = $args['commit_sha'] ?? null;

        if ($commit_sha !== null && (!is_string($commit_sha) || !preg_match('/^[0-9a-f]{7,40}$/i', $commit_sha))) {
            throw new InvalidArgumentException('commit_sha must be 7 to 40 hex characters');
        }

        $deployment_id = $this->model->queue_deploy($project_id, (int) $app->id, $this->_customer_id(), 'mcp', $commit_sha);

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

        $deployment_id = $this->model->queue_rollback($project_id, (int) $app->id, $this->_customer_id(), $target_id);

        return [
            'deployment_id' => $deployment_id,
            'status' => 'pending',
            'app' => ['id' => (int) $app->id, 'label' => $app->label],
            'next' => 'Follow progress with get_deployment_logs {"deployment_id": ' . $deployment_id . '}',
        ];
    }

    function _tool_upgrade_trongate(array $args): array {
        [$project_id, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $upgrade_id = $this->model->queue_upgrade($project_id, (int) $app->id, $this->_customer_id());

        return [
            'upgrade_id' => $upgrade_id,
            'status' => 'pending',
            'app' => ['id' => (int) $app->id, 'label' => $app->label],
            'next' => 'The pull request link shows in get_app {"app_id": ' . (int) $app->id . '} as trongate_upgrade once it is open',
        ];
    }

    /**
     * The latest Trongate upgrade (App_upgrades::latest_upgrade) for get_app
     */
    function _upgrade_summary(object|false $upgrade): ?array {
        if ($upgrade === false) {
            return null;
        }

        return [
            'status' => $upgrade->status,
            'from_version' => $upgrade->from_version,
            'to_version' => $upgrade->to_version,
            'pull_request' => $upgrade->pr_url,
            'files' => $upgrade->summary,
            'error' => $upgrade->error,
            'checked_at' => $upgrade->completed_at ?? $upgrade->created_at,
        ];
    }

    function _tool_get_app_logs(array $args): array {
        [, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));

        if (empty($app->current_deployment_id)) {
            throw new RuntimeException('This app was never deployed, so it has no logs.');
        }

        $logs = $this->_live_app_logs($app, $this->_string_arg($args, 'since', false));

        if ($logs === null) {
            throw new RuntimeException('Could not read the logs from the cluster right now. Try again.');
        }

        $lines = $logs['lines'];

        if ($this->_bool_arg($args, 'problems_only', false)) {
            $lines = array_values(array_filter($lines, fn(array $line) => in_array($line['level'], ['warn', 'error'], true)));
        }

        return [
            'state' => $logs['state'],
            'lines' => $lines,
            'next_since' => $logs['cursor'],
            'note' => $logs['pods'] === 0 ? 'No running pods: the app is asleep or stopped, so there are no logs to read.' : null,
        ];
    }

    function _tool_list_env_vars(array $args): array {
        [, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));

        return ['names' => array_keys($this->_app_env($app)), 'reserved' => App_env::RESERVED];
    }

    function _tool_set_env_var(array $args): array {
        [, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $this->editing_app_id = (int) $app->id;

        $name = trim($this->_string_arg($args, 'name'));
        $value = $args['value'] ?? null;

        if (!is_string($value) || $value === '' || strlen($value) > App_env::MAX_VALUE_BYTES) {
            throw new InvalidArgumentException('value must be a string of 1 to ' . App_env::MAX_VALUE_BYTES . ' bytes');
        }

        $this->_check($this->_validate_env_name($name));
        $this->secrets->update(App_env::MODULE, (int) $app->id, App_env::SERVICE, [$name => $value]);

        return ['saved' => $name, 'next' => 'It takes effect on the next deploy: deploy_app {"app_id": ' . $app->id . '}'];
    }

    function _tool_delete_env_var(array $args): array {
        [, $app] = $this->_app_or_fail($this->_int_arg($args, 'app_id'));
        $name = $this->_string_arg($args, 'name');
        $env = $this->_app_env($app);

        if (!array_key_exists($name, $env)) {
            throw new RuntimeException("{$name} is not set on this app");
        }

        unset($env[$name]);

        $env === []
            ? $this->secrets->delete(App_env::MODULE, (int) $app->id, App_env::SERVICE)
            : $this->secrets->save(App_env::MODULE, (int) $app->id, App_env::SERVICE, $env);

        return ['removed' => $name, 'next' => 'It is gone from the app after the next deploy: deploy_app {"app_id": ' . $app->id . '}'];
    }

    function _tool_list_services(array $args): array {
        $project_id = $this->_member_project_id_or_fail($this->_int_arg($args, 'project_id'));

        return ['services' => array_map(fn(object $service) => $this->_service_summary($service), $this->model->project_services($project_id))];
    }

    function _tool_add_service(array $args): array {
        $project_id = $this->_member_project_id_or_fail($this->_int_arg($args, 'project_id'));
        $name = $this->_string_arg($args, 'name');
        $env = $args['env'] ?? [];

        if (!is_array($env)) {
            throw new InvalidArgumentException('env must be an object of names to values');
        }

        foreach ($env as $key => $value) {
            $this->_check(App_env::name_error((string) $key) ?? true);

            if (!is_string($value) || strlen($value) > App_env::MAX_VALUE_BYTES) {
                throw new InvalidArgumentException("{$key} must be a string of at most " . App_env::MAX_VALUE_BYTES . ' bytes');
            }
        }

        $service_id = $this->model->add_service($project_id, [
            'name' => $name,
            'image' => $this->_string_arg($args, 'image'),
            'port' => $this->_int_arg($args, 'port', false, Project_service::DEFAULT_PORT),
            'memory_mb' => $this->_int_arg($args, 'memory_mb', false, Project_service::DEFAULT_MEMORY_MB),
        ]);

        if ($env !== []) {
            $this->module('secrets');
            $this->secrets->save(Project_service::MODULE, $service_id, Project_service::SERVICE, array_map('strval', $env));
        }

        $this->model->queue_service_deploy($project_id, $service_id);
        $service = $this->model->find_project_service($project_id, $service_id);

        return [
            'service' => $service === false ? ['id' => $service_id] : $this->_service_summary($service),
            'next' => 'It is starting (list_services shows when it runs). Deploy the project\'s apps to give them ' . Project_service::url_variable($name) . '.',
        ];
    }

    function _tool_redeploy_service(array $args): array {
        $project_id = $this->_member_project_id_or_fail($this->_int_arg($args, 'project_id'));
        $this->model->queue_service_deploy($project_id, $this->_int_arg($args, 'service_id'));

        return ['queued' => true];
    }

    function _tool_delete_service(array $args): array {
        $project_id = $this->_member_project_id_or_fail($this->_int_arg($args, 'project_id'));
        $this->model->queue_service_delete($project_id, $this->_int_arg($args, 'service_id'));

        return ['deleted' => true, 'next' => "Deploy the project's apps to drop its URL from them."];
    }

    function _service_summary(object $service): array {
        return [
            'id' => (int) $service->id,
            'name' => $service->name,
            'image' => $service->image,
            'port' => (int) $service->port,
            'memory_mb' => (int) $service->memory_mb,
            'status' => $service->status ?? 'queued',
            'deployed_at' => $service->deployed_at,
            'app_variable' => Project_service::url_variable($service->name),
            'url' => Project_service::url((int) $service->id, (int) $service->port),
        ];
    }

    /**
     * A project the customer is a member of
     *
     * @throws RuntimeException when it is not
     */
    function _member_project_id_or_fail(int $project_id): int {
        if ($this->model->find_member_project((int) $this->customer->id, $project_id) === false) {
            throw new RuntimeException('Project not found');
        }

        return $project_id;
    }

    // --- Helpers ---

    /**
     * Who deploys and rollbacks are recorded as: the customer, or null for the admin
     */
    function _customer_id(): ?int {
        return $this->customer === null ? null : (int) $this->customer->id;
    }

    /**
     * The app's project id and row (with its namespace): one of the
     * customer's apps, or any app for the admin.
     *
     * @return array{int, object}
     * @throws RuntimeException when there is no such app
     */
    function _app_or_fail(int $app_id): array {
        if ($this->_is_admin()) {
            $project_id = $this->model->app_project_id($app_id);
            $app = $project_id === false ? false : $this->model->find_project_app($project_id, $app_id);
        } else {
            $app = $this->model->find_member_app((int) $this->customer->id, $app_id);
            $project_id = $app === false ? false : (int) $app->project_id;
        }

        if ($project_id === false || $app === false) {
            throw new RuntimeException('App not found');
        }

        return [$project_id, $app];
    }

    /**
     * Turn a validation callback's message into a tool error
     *
     * @throws InvalidArgumentException
     */
    function _check(bool|string $valid): void {
        if ($valid !== true) {
            throw new InvalidArgumentException(is_string($valid) ? $valid : 'Invalid value');
        }
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
     * @throws InvalidArgumentException when missing (and required) or not a string
     */
    function _string_arg(array $args, string $key, bool $required = true, string $default = ''): string {
        if (!array_key_exists($key, $args) || $args[$key] === null) {
            if ($required) {
                throw new InvalidArgumentException("Missing required argument: {$key}");
            }
            return $default;
        }

        if (!is_string($args[$key]) || ($required && trim($args[$key]) === '')) {
            throw new InvalidArgumentException("Argument {$key} must be a non-empty string");
        }

        return $args[$key];
    }

    /**
     * @throws InvalidArgumentException when not a boolean
     */
    function _bool_arg(array $args, string $key, bool $default = false): bool {
        $value = $args[$key] ?? $default;

        if (!is_bool($value)) {
            throw new InvalidArgumentException("Argument {$key} must be true or false");
        }

        return $value;
    }

    /**
     * A list of strings, or one string split on whitespace and commas
     *
     * @return list<string>
     * @throws InvalidArgumentException
     */
    function _strings_arg(array $args, string $key): array {
        $value = $args[$key] ?? [];

        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', trim($value)) ?: [];
        }

        if (!is_array($value) || array_filter($value, fn($item) => !is_string($item)) !== []) {
            throw new InvalidArgumentException("Argument {$key} must be a list of strings");
        }

        return array_values(array_filter(array_map('trim', $value), fn(string $item) => $item !== ''));
    }

    /**
     * The custom domain and how far it got: requested, verified (TXT record),
     * pointed here (DNS) and secured (certificate). Null without one.
     */
    function _domain_status(object $app): ?array {
        $domain = (string) ($app->domain ?? '');

        if ($domain === '') {
            return null;
        }

        $verified = $domain === (string) ($app->verified_domain ?? '');

        return [
            'domain' => $domain,
            'verified' => $verified,
            'pointed_here' => $verified && !empty($app->domain_pointed_at),
            'secured' => $verified && !empty($app->domain_secured_at),
            'verification_record' => $verified ? null : ['type' => 'TXT'] + $this->model->domain_verification_record((int) $app->id, $domain),
        ];
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
            'environment' => $app->environment ?? 'production',
            'staging_app_id' => isset($app->staging_app_id) ? (int) $app->staging_app_id : null,
            'always_on' => !empty($app->always_on),
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
            // Why it failed and how to recover (Config_error::to_array)
            'failure' => $deployment->status === 'failed' ? json_decode((string) ($deployment->failure ?? ''), true) : null,
        ];
    }
}
