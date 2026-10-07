<?php

require_once APPPATH . 'modules/platform/live/App_resource_audit.php';

/**
 * Admin-only MCP tools (MCP_SECRET): the platform across all projects, and
 * finding and retrying cleanups that did not finish. Registered by Mcp_tools.
 */
trait Mcp_admin_tools {

    function _admin_tools(): array {
        return [
            [
                'name' => 'platform_status',
                'title' => 'Platform status',
                'description' => 'Platform overview (all projects, or one): apps and deployments by status, deployments in progress and recent failures.',
                'inputSchema' => $this->_schema(['project_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional: only this project']]),
                'annotations' => $this->_hints(true),
                'scopes' => ['admin'],
            ],
            [
                'name' => 'audit_app_resources',
                'title' => 'Audit app resources',
                'description' => "List cluster objects labelled as a trongate.cloud app that no longer match a live app in their project's namespace, deleted apps whose cleanup (destroy_app) failed or has not finished, and deleted projects whose namespace removal (destroy_project) failed or has not finished. Read-only: reports, never repairs.",
                'inputSchema' => $this->_schema(),
                'annotations' => $this->_hints(true),
                'scopes' => ['admin'],
            ],
            [
                'name' => 'retry_app_cleanup',
                'title' => 'Retry app cleanup',
                'description' => "Queue destroy_app again for a deleted app whose cleanup failed (see audit_app_resources). It removes the app's objects that carry the app's labels; the database and files are kept.",
                'inputSchema' => $this->_schema(['app_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Deleted app id (from audit_app_resources)']], ['app_id']),
                'annotations' => $this->_hints(false, true),
                'scopes' => ['admin'],
            ],
            [
                'name' => 'retry_project_cleanup',
                'title' => 'Retry project cleanup',
                'description' => 'Queue destroy_project again for a deleted project whose namespace is still there (see audit_app_resources). It deletes the namespace only if no app object is left in it.',
                'inputSchema' => $this->_schema(['project_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Deleted project id (from audit_app_resources)']], ['project_id']),
                'annotations' => $this->_hints(false, true),
                'scopes' => ['admin'],
            ],
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
}
