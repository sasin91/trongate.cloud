<?php

require_once APPPATH . 'modules/platform/manifests/Build_commands.php';

/**
 * App writes shared by onboarding and the apps module.
 * Requires the Platform_actions trait in the same model (slug helper).
 *
 * @property-read Db $db
 */
trait App_records {

    /**
     * Create an app with a unique slug. The repository must be one the GitHub
     * App is installed on: deploys read it with an installation token.
     */
    function create_app(int $project_id, string $label, string $type, string $source_url, string $source_provider, string $source_branch = 'main', ?string $domain = null, bool $auto_deploy = true): int|false {
        return $this->db->insert([
            'project_id' => $project_id,
            'label' => $label,
            'slug' => $this->generate_app_slug($label),
            'type' => $type,
            'source_url' => $source_url,
            'source_provider' => $source_provider,
            'source_branch' => $source_branch ?: 'main',
            'domain' => $domain,
            'auto_deploy' => $auto_deploy ? 1 : 0,
            'persistent_paths' => '[]',
        ], 'app');
    }

    /**
     * The app's processes (Manifests::_process_deployment), by name
     *
     * @return list<array{name: string, command: string, replicas: int}>
     */
    function app_processes(int $app_id): array {
        $rows = $this->db->query_bind('SELECT name, command, replicas FROM app_process WHERE app_id = :app_id ORDER BY name', ['app_id' => $app_id], 'array');

        return array_map(fn(array $row) => ['name' => (string) $row['name'], 'command' => (string) $row['command'], 'replicas' => (int) $row['replicas']], $rows ?: []);
    }

    /**
     * Replace the app's processes with these (checked by _processes_error);
     * they take effect on the next deploy.
     *
     * @param list<array{name: string, command: string, replicas: int}> $processes
     */
    function save_app_processes(int $app_id, array $processes): void {
        $this->db->transaction(function () use ($app_id, $processes) {
            $names = array_column($processes, 'name');
            $keep = $names === [] ? "''" : implode(',', array_map(fn(int $i) => ":name_{$i}", array_keys($names)));
            $params = ['app_id' => $app_id];
            foreach ($names as $i => $name) {
                $params["name_{$i}"] = $name;
            }
            $this->db->query_bind("DELETE FROM app_process WHERE app_id = :app_id AND name NOT IN ({$keep})", $params);

            foreach ($processes as $process) {
                $this->db->query_bind(
                    'INSERT INTO app_process (app_id, name, command, replicas) VALUES (:app_id, :name, :command, :replicas)
                     ON DUPLICATE KEY UPDATE command = VALUES(command), replicas = VALUES(replicas)',
                    ['app_id' => $app_id, 'name' => $process['name'], 'command' => $process['command'], 'replicas' => $process['replicas']]
                );
            }

            return true; // Db::transaction commits only on a truthy result
        });
    }

    /**
     * Give a new app, which has none yet, its processes (checked by
     * _processes_error). No transaction of its own, so app creation can run
     * it inside one.
     *
     * @param list<array{name: string, command: string, replicas: int}> $processes
     */
    function add_app_processes(int $app_id, array $processes): void {
        foreach ($processes as $process) {
            $this->db->query_bind(
                'INSERT INTO app_process (app_id, name, command, replicas) VALUES (:app_id, :name, :command, :replicas)',
                ['app_id' => $app_id, 'name' => $process['name'], 'command' => $process['command'], 'replicas' => $process['replicas']]
            );
        }
    }

    /**
     * The extra PHP extensions the app's image build installs, in the order
     * they were added
     *
     * @return list<string>
     */
    function app_php_extensions(int $app_id): array {
        $rows = $this->db->query_bind('SELECT name FROM app_php_extension WHERE app_id = :app_id ORDER BY id', ['app_id' => $app_id], 'array');

        return array_column($rows ?: [], 'name');
    }

    /**
     * The shell lines the app's image build runs (Build_commands); '' when none
     */
    function app_build_commands(int $app_id): string {
        $commands = $this->db->query_bind('SELECT commands FROM app_build WHERE app_id = :app_id', ['app_id' => $app_id], 'column');

        return (string) ($commands ?: '');
    }

    /**
     * Replace the app's extra PHP extensions with these (checked by
     * _validate_php_extensions); they take effect on the next deploy
     *
     * @param list<string> $names
     */
    function save_app_php_extensions(int $app_id, array $names): void {
        $keep = $names === [] ? "''" : implode(',', array_map(fn(int $i) => ":name_{$i}", array_keys($names)));
        $params = ['app_id' => $app_id];
        foreach ($names as $i => $name) {
            $params["name_{$i}"] = $name;
        }
        $this->db->query_bind("DELETE FROM app_php_extension WHERE app_id = :app_id AND name NOT IN ({$keep})", $params);

        foreach ($names as $name) {
            $this->db->query_bind('INSERT IGNORE INTO app_php_extension (app_id, name) VALUES (:app_id, :name)', ['app_id' => $app_id, 'name' => $name]);
        }
    }

    /**
     * Set the app's build commands (checked by _validate_build_commands);
     * '' removes them. They take effect on the next deploy.
     */
    function save_app_build_commands(int $app_id, string $commands): void {
        $commands = Build_commands::normalise($commands);

        if ($commands === '') {
            $this->db->query_bind('DELETE FROM app_build WHERE app_id = :app_id', ['app_id' => $app_id]);
            return;
        }

        $this->db->query_bind(
            'INSERT INTO app_build (app_id, commands) VALUES (:app_id, :commands)
             ON DUPLICATE KEY UPDATE commands = VALUES(commands)',
            ['app_id' => $app_id, 'commands' => $commands]
        );
    }

    /**
     * The SQL the app's database starts with (Setup_sql); its first
     * deploy runs it
     */
    function set_setup_sql(int $app_id, string $sql): void {
        $this->db->query_bind("UPDATE app SET setup_sql = :sql, setup_sql_status = 'pending' WHERE id = :app_id", ['app_id' => $app_id, 'sql' => $sql]);
    }

    /**
     * Hide a failed setup SQL's alert: the SQL that did not run is cleared,
     * the error kept
     */
    function dismiss_setup_sql(int $app_id): void {
        $this->db->query_bind("UPDATE app SET setup_sql = NULL, setup_sql_status = 'dismissed' WHERE id = :app_id AND setup_sql_status = 'failed'", ['app_id' => $app_id]);
    }

    /**
     * Update an app's platform settings (the app must be in the project).
     * The custom domain goes through update_app_domain().
     *
     * No transaction of its own, so app creation can run it inside one.
     *
     * @param array{domain: ?string, persistent_paths: list<string>, php_extensions: list<string>, build_commands: string, always_on: bool, auto_deploy: bool, source_branch: string, trongate_env: string, mail_capture: bool, health_path: string} $settings
     */
    function update_app_settings(int $project_id, int $app_id, array $settings): void {
        if ($this->find_project_app($project_id, $app_id) === false) {
            return;
        }

        $this->update_app_domain($project_id, $app_id, $settings['domain']);
        $this->save_app_php_extensions($app_id, $settings['php_extensions']);
        $this->save_app_build_commands($app_id, $settings['build_commands']);

        $sql = <<<SQL
            UPDATE app
            SET persistent_paths = :persistent_paths,
                always_on = :always_on,
                trongate_env = :trongate_env,
                mail_capture = :mail_capture,
                health_path = :health_path,
                auto_deploy = :auto_deploy,
                source_branch = :source_branch
            WHERE id = :app_id AND project_id = :project_id
        SQL;
        $this->db->query_bind($sql, [
            'persistent_paths' => json_encode($settings['persistent_paths']),
            'always_on' => $settings['always_on'] ? 1 : 0,
            'trongate_env' => $settings['trongate_env'],
            'mail_capture' => $settings['mail_capture'] ? 1 : 0,
            'health_path' => $settings['health_path'] === '/' ? '' : $settings['health_path'],
            'auto_deploy' => $settings['auto_deploy'] ? 1 : 0,
            'source_branch' => $settings['source_branch'],
            'app_id' => $app_id,
            'project_id' => $project_id,
        ]);
    }

    /**
     * Set or clear an app's requested custom domain (the app must be in the
     * project). Keeping the same domain changes nothing; a new one starts
     * unverified and is checked afresh once verified.
     */
    function update_app_domain(int $project_id, int $app_id, ?string $domain): void {
        $old = $this->find_project_app($project_id, $app_id);
        $old_domain = $old !== false ? (string) ($old->verified_domain ?? '') : '';

        $sql = <<<SQL
            UPDATE app
            SET domain = :domain,
                -- Before verified_domain: MariaDB assigns left to right, so these
                -- still compare against the old value. A new domain starts ungated
                -- and is checked afresh once verified.
                domain_pointed_at = IF(verified_domain <=> :same_domain_1, domain_pointed_at, NULL),
                domain_dns_failures = IF(verified_domain <=> :same_domain_2, domain_dns_failures, 0),
                domain_checked_at = IF(verified_domain <=> :same_domain_3, domain_checked_at, NULL),
                domain_secured_at = IF(verified_domain <=> :same_domain_4, domain_secured_at, NULL),
                verified_domain = IF(verified_domain <=> :same_domain, verified_domain, NULL)
            WHERE id = :app_id AND project_id = :project_id
        SQL;
        $this->db->query_bind($sql, [
            'domain' => $domain,
            'same_domain' => $domain,
            'same_domain_1' => $domain,
            'same_domain_2' => $domain,
            'same_domain_3' => $domain,
            'same_domain_4' => $domain,
            'app_id' => $app_id,
            'project_id' => $project_id,
        ]);

        // Giving up a verified domain frees it for any other app at once, so
        // its Gateway and certificate leave the cluster now, not on this
        // app's next deploy (tasks/prune_domains.php)
        if ($old_domain !== '' && $old_domain !== (string) $domain) {
            $this->_queue_task($project_id, $app_id, null, 'prune_domains', [
                'project_id' => $project_id,
                'app_id' => $app_id,
            ]);
        }
    }

    /**
     * Whether another app already serves (has verified) this custom domain.
     * Unverified requests never block anyone: the DNS owner wins.
     */
    function domain_taken(string $domain, ?int $ignore_app_id = null): bool {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM app
            WHERE verified_domain = :domain AND id <> :ignore_id
        SQL;
        $count = $this->db->query_bind($sql, ['domain' => $domain, 'ignore_id' => $ignore_app_id ?? 0], 'column');

        return (int) $count > 0;
    }
}
