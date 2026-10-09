<?php

/**
 * Trongate upgrades of an app's repository (modules/platform/tasks/upgrade_app.php):
 * queue one, and read the latest. Shared by the app's Settings page and the
 * MCP tools.
 *
 * The model must also use Platform_actions.
 *
 * @property-read Db $db
 */
trait App_upgrades {

    /**
     * Queue an upgrade pull request for the app. While one is waiting or
     * running, its id is returned instead of queueing another.
     *
     * @return int The app_upgrade id
     * @throws RuntimeException when the app is not the project's or not a Trongate v2 app with a repository
     */
    function queue_upgrade(int $project_id, int $app_id, ?int $customer_id): int {
        $app = $this->_app_or_fail($project_id, $app_id);

        if ($app->type !== 'trongate_v2') {
            throw new RuntimeException('Upgrades are for Trongate v2 apps.');
        }

        if (empty($app->source_url)) {
            throw new RuntimeException('App has no source repository');
        }

        return $this->db->transaction(function () use ($project_id, $app_id, $customer_id) {
            $open = $this->db->query_bind(
                "SELECT id FROM app_upgrade WHERE app_id = :app_id AND status IN ('pending', 'running') ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['app_id' => $app_id],
                'column'
            );

            if ($open !== false) {
                return (int) $open;
            }

            $upgrade_id = $this->db->insert(['app_id' => $app_id, 'customer_id' => $customer_id], 'app_upgrade');
            $this->_queue_task($project_id, $app_id, null, 'upgrade_app', [
                'upgrade_id' => $upgrade_id,
                'app_id' => $app_id,
                'project_id' => $project_id,
            ]);

            return $upgrade_id;
        });
    }

    /**
     * The app's most recent upgrade, with summary decoded (Upgrade_plan::counts)
     */
    function latest_upgrade(int $app_id): object|false {
        $rows = $this->db->query_bind(
            'SELECT * FROM app_upgrade WHERE app_id = :app_id ORDER BY id DESC LIMIT 1',
            ['app_id' => $app_id],
            'object'
        );
        $upgrade = $rows[0] ?? false;

        if ($upgrade !== false) {
            $upgrade->summary = json_decode((string) $upgrade->summary, true) ?: [];
        }

        return $upgrade;
    }
}
