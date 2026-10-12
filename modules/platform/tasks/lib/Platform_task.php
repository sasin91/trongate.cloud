<?php

require_once __DIR__ . '/../../live/Setup_sql.php';

/**
 * Helpers shared by the platform task scripts (deploy, rollback, destroy_app,
 * destroy_project, recheck_domains, prune_domains).
 *
 * Lives in tasks/lib/ so the Runtime can never run it as a task
 * (it only includes tasks/{script}.php). Framework-free.
 */
class Platform_task {

    /** How long a rollout may take before the deployment fails */
    const ROLLOUT_TIMEOUT_SECONDS = 300;

    /** How long a per-build BuildKit daemon may take to become ready */
    const BUILDKITD_READY_SECONDS = 180;

    /** Daemon log lines copied into the task log when a build fails (log_buildkitd) */
    const BUILDKITD_LOG_LINES = 60;

    /**
     * Smoke test attempts after a rollout (smoke_test). The route, a new
     * certificate or a sleeping app's wake-up can lag the rollout, so it backs
     * off from 1 s to 5 s between attempts: about 2 minutes in all.
     */
    const SMOKE_TEST_ATTEMPTS = 30;

    /**
     * 200 bodies that mean no app answered: Traefik's (Go net/http's) reply
     * when no route matches, and Traefik's when a route has no live backend
     */
    const FALLBACK_BODIES = ['404 page not found', 'no available server'];

    /**
     * Attempts, 2 s apart, to open a new app's database for its setup SQL:
     * mariadb-operator creates the database, user and grant after the deploy
     * applies them
     */
    const DATABASE_READY_ATTEMPTS = 30;

    /**
     * Fetch one row with a prepared statement
     */
    static function row(PDO $db, string $sql, array $params = []): ?array {
        return Runtime::run($db, $sql, $params)->fetch() ?: null;
    }

    /**
     * Fetch all rows with a prepared statement
     */
    static function rows(PDO $db, string $sql, array $params = []): array {
        return Runtime::run($db, $sql, $params)->fetchAll();
    }

    /**
     * Run a write with a prepared statement; returns the affected row count
     */
    static function execute(PDO $db, string $sql, array $params = []): int {
        return Runtime::run($db, $sql, $params)->rowCount();
    }

    /**
     * The app row plus its project's namespace and owner_id (project_owner_id);
     * fails unless it belongs to the project and neither is deleted
     */
    static function app(PDO $db, int $project_id, int $app_id): array {
        $sql = <<<SQL
            SELECT a.*, p.namespace, p.owner_id AS project_owner_id
            FROM app a
            JOIN project p ON p.id = a.project_id
            WHERE a.id = ? AND a.project_id = ? AND a.deleted_at IS NULL AND p.deleted_at IS NULL
            SQL;

        $app = self::row($db, $sql, [$app_id, $project_id]);

        if ($app === null) {
            throw new RuntimeException("App #{$app_id} not found for project #{$project_id} (or deleted)");
        }

        return $app;
    }

    /**
     * Whether a project (customer) task is queued and waiting for the worker.
     * The worker runs one task at a time, so periodic tasks that loop over
     * apps check this and stop early rather than hold up a deploy.
     */
    static function project_task_waiting(PDO $db): bool {
        $row = self::row(
            $db,
            'SELECT id FROM tasks
             WHERE project_id IS NOT NULL
               AND started_at IS NULL
               AND completed_at IS NULL
               AND failed_at IS NULL
             LIMIT 1'
        );

        return $row !== null;
    }

    /**
     * The namespace of a project, deleted or not (destroy_app and
     * destroy_project run after the rows are soft-deleted)
     */
    static function project_namespace(PDO $db, int $project_id): string {
        $project = self::row($db, 'SELECT namespace FROM project WHERE id = ?', [$project_id]);

        if ($project === null) {
            throw new RuntimeException("Project #{$project_id} not found");
        }

        return Manifests::namespace_name($project);
    }

    /**
     * A GitHub client that can read one repository (owner/name): an
     * installation token of the trongate.cloud GitHub App, so builds do not
     * depend on whoever added the app staying signed in.
     *
     * @throws Client_Error 404 when the App is not installed on the repository
     */
    static function github_client(string $repo): Github {
        return new Github(Github_app::from_env()->repository_token($repo));
    }

    /**
     * The deployment row; fails unless it belongs to the app
     */
    static function deployment(PDO $db, int $app_id, int $deployment_id): array {
        $sql = <<<SQL
            SELECT *
            FROM deployment
            WHERE id = ? AND app_id = ?
            SQL;

        $deployment = self::row($db, $sql, [$deployment_id, $app_id]);

        if ($deployment === null) {
            throw new RuntimeException("Deployment #{$deployment_id} not found for app #{$app_id}");
        }

        return $deployment;
    }

    /**
     * The app's database server. Places the app on the active server
     * with the fewest apps (and spare capacity) on first deploy.
     */
    static function database_server(PDO $db, array &$app, Task_log $log): array {
        if (!empty($app['database_server_id'])) {
            $server = self::row($db, 'SELECT * FROM database_server WHERE id = ?', [(int) $app['database_server_id']]);

            if ($server === null) {
                throw new RuntimeException("Database server #{$app['database_server_id']} no longer exists");
            }

            return $server;
        }

        $sql = <<<SQL
            SELECT ds.*, COUNT(a.id) AS app_count
            FROM database_server ds
            LEFT JOIN app a ON a.database_server_id = ds.id
            WHERE ds.active = 1
            GROUP BY ds.id
            HAVING app_count < ds.capacity
            ORDER BY app_count, ds.id
            LIMIT 1
            SQL;

        $server = self::row($db, $sql);

        if ($server === null) {
            throw new RuntimeException('No active database server has spare capacity');
        }

        // Only claim when still unplaced, so two concurrent deploys agree on one server
        $server_id = (int) $server['id'];
        $app_id = (int) $app['id'];
        $claimed = self::execute(
            $db,
            'UPDATE app SET database_server_id = ? WHERE id = ? AND database_server_id IS NULL',
            [$server_id, $app_id]
        ) > 0;

        if (!$claimed) {
            $fresh = self::row($db, 'SELECT database_server_id FROM app WHERE id = ?', [$app_id]);
            $app['database_server_id'] = $fresh['database_server_id'] ?? null;

            return self::database_server($db, $app, $log);
        }

        $app['database_server_id'] = $server_id;
        $log->info("Placed database on {$server['label']}");

        return $server;
    }

    /**
     * Apply the tenant namespace and the app's manifests, then remove
     * custom-domain Gateways the app no longer routes.
     *
     * Re-reads the custom-domain gate first: a build takes minutes, and
     * recheck_domains leaves promoting a domain to a deploy that is already
     * running. $app comes back with the values that were applied.
     */
    static function apply(Runtime $runtime, Task_log $log, array &$app, array $deployment, array $database): void {
        $kube = $runtime->kube();
        $namespace = Manifests::namespace_name($app);

        $gate = self::row(
            $runtime->db(),
            'SELECT verified_domain, domain_pointed_at, domain_secured_at FROM app WHERE id = ?',
            [(int) $app['id']],
        );
        $app = ($gate ?? []) + $app;

        $log->info("Applying tenant namespace {$namespace}");
        $kube->apply_all(Manifests::tenant($namespace));

        self::release_domains($runtime, $log, $app);

        // The app's own variables win over a service URL of the same name
        $env = self::app_env($runtime, $log, (int) $app['id']) + self::service_urls($runtime, $log, (int) $app['project_id']);
        $deployment['config_files'] = self::config_files($deployment);
        $app['processes'] = self::rows(
            $runtime->db(),
            'SELECT name, command, replicas FROM app_process WHERE app_id = ? ORDER BY name',
            [(int) $app['id']],
        );
        $manifests = Manifests::app($app, $deployment, $database, self::ensure_session_user($runtime, $log, (int) $app['id']), $env);

        foreach ($manifests as $manifest) {
            // Names and kinds only: Secrets carry the database password
            $log->info("Applying {$manifest['kind']} {$manifest['metadata']['name']}");
            $kube->apply($manifest);
        }

        // Processes that were removed: their Deployments go
        $kept = array_map(fn(array $p) => Manifests::process_deployment_name((int) $app['id'], $p['name']), Manifests::processes($app));
        foreach ($kube->list('apps/v1', 'Deployment', $namespace, Manifests::process_selector((int) $app['id'])) as $process) {
            $name = (string) $process['metadata']['name'];
            if (!in_array($name, $kept, true) && $kube->delete('apps/v1', 'Deployment', $namespace, $name)) {
                $log->info("Removed process {$name}");
            }
        }

        // What redeploy_outdated_apps compares with Trongate_config::CONFIG_VERSION
        self::execute($runtime->db(), 'UPDATE deployment SET config_version = ? WHERE id = ?', [Trongate_config::CONFIG_VERSION, (int) $deployment['id']]);

        self::prune_domain_gateways($runtime, $log, $app);
    }

    /**
     * The deployment's own config/*.php (JSON: file name => source), stored by
     * the deploy that built it; Manifests::app edits them and mounts the
     * edited ones over the image's
     */
    static function config_files(array $deployment): string {
        if (!isset($deployment['config_files'])) {
            require_once __DIR__ . '/../../manifests/Config_error.php';

            throw new Config_error("Deployment #{$deployment['id']} was built before trongate.cloud kept a copy of its config files, so it cannot be applied again.", Config_error::REDEPLOY);
        }

        return (string) $deployment['config_files'];
    }

    /**
     * owner/name of the app's GitHub repository
     */
    static function github_repo(array $app): string {
        $source_url = rtrim((string) ($app['source_url'] ?? ''), '/');

        if (($app['source_provider'] ?? '') !== 'github' || !preg_match('#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(\.git)?$#', $source_url, $match)) {
            throw new RuntimeException('App has no valid GitHub repository URL');
        }

        return $match[1];
    }

    /**
     * The app's environment variables (App_env), decrypted. Names only go to
     * the log.
     *
     * @return array<string, string>
     */
    static function app_env(Runtime $runtime, Task_log $log, int $app_id): array {
        $env = $runtime->resolve_reference(App_env::reference($app_id));

        if (!is_array($env) || $env === []) {
            return [];
        }

        $env = array_map('strval', $env);
        $log->info('Environment variables: ' . implode(', ', array_keys($env)));

        return $env;
    }

    /**
     * {NAME}_URL of each of the project's live services (Project_service)
     *
     * @return array<string, string>
     */
    static function service_urls(Runtime $runtime, Task_log $log, int $project_id): array {
        $services = self::rows(
            $runtime->db(),
            'SELECT id, name, port FROM service WHERE project_id = ? AND deleted_at IS NULL ORDER BY name',
            [$project_id]
        );
        $urls = Project_service::app_urls($services);

        if ($urls !== []) {
            $log->info('Service URLs: ' . implode(', ', array_keys($urls)));
        }

        return $urls;
    }

    /**
     * Create or refresh the app's Valkey ACL user for PHP sessions and persist
     * it (ACL SAVE, to the users.acl on Valkey's volume). Idempotent: the
     * password is derived from APP_KEY, so every apply rewrites the same user.
     *
     * Returns the settings for Manifests::sessions_ini, or null when the
     * worker has no Valkey credentials (the app keeps file sessions).
     *
     * @return array{host: string, port: int, password: string}|null
     * @throws RuntimeException when Valkey is configured but unreachable
     */
    static function ensure_session_user(Runtime $runtime, Task_log $log, int $app_id): ?array {
        $valkey = $runtime->valkey();

        if ($valkey === null) {
            $log->warn('Valkey is not configured for the worker; PHP sessions stay in the pod');

            return null;
        }

        $user = Manifests::session_user($app_id);
        $password = Valkey_sessions::set_user($valkey, $user);
        $log->info("Session store ready (Valkey user {$user})");

        return ['host' => VALKEY_HOST, 'port' => VALKEY_PORT, 'password' => $password];
    }

    /**
     * Remove the app's Valkey ACL user. Its session keys expire on their own
     * (session.gc_maxlifetime), so they are left to do so.
     */
    static function delete_session_user(Runtime $runtime, int $app_id): bool {
        $valkey = $runtime->valkey();

        if ($valkey === null) {
            return false;
        }

        $deleted = (int) $valkey->command('ACL', 'DELUSER', Manifests::session_user($app_id));
        $valkey->command('ACL', 'SAVE');

        return $deleted > 0;
    }

    /**
     * Delete the app's custom-domain Gateways for domains it no longer routes
     * (changed, removed, or demoted by recheck_domains). Also run on its own by
     * the prune_domains task, queued when a verified domain is changed or
     * removed, so an app that does not deploy again still lets go of it.
     * Never throws: logs instead.
     */
    static function prune_domain_gateways(Runtime $runtime, Task_log $log, array $app): bool {
        $app_id = (int) $app['id'];
        $namespace = Manifests::namespace_name($app);
        $keep = array_map(
            fn(string $domain) => Manifests::domain_gateway_name($app_id, $domain),
            Manifests::custom_domains($app)
        );

        try {
            $gateways = $runtime->kube()->list('gateway.networking.k8s.io/v1', 'Gateway', $namespace, Manifests::domain_gateway_selector($app_id));

            foreach ($gateways as $gateway) {
                $name = (string) ($gateway['metadata']['name'] ?? '');

                if ($name !== '' && !in_array($name, $keep, true)) {
                    self::delete_domain_gateway($runtime, $namespace, $name);
                    $log->info("Deleted Gateway {$name} and its certificate (custom domain no longer routed)");
                }
            }
        } catch (Throwable $e) {
            $log->warn("Could not prune custom-domain Gateways: {$e->getMessage()}");
            return false;
        }

        return true;
    }

    /**
     * Delete other apps' Gateways for the domains this app routes, in
     * any tenant namespace. app.verified_domain is UNIQUE, so this app
     * owns them now; a Gateway left behind by the previous owner (its
     * prune_domains task failed, or has not run yet) would be a second HTTPS
     * listener for the hostname, with Traefik choosing between two apps. Never
     * throws: logs instead.
     */
    static function release_domains(Runtime $runtime, Task_log $log, array $app): void {
        foreach (Manifests::custom_domains($app) as $domain) {
            try {
                $gateways = $runtime->kube()->list('gateway.networking.k8s.io/v1', 'Gateway', null, Manifests::domain_hostname_selector($domain));

                foreach ($gateways as $gateway) {
                    $namespace = (string) ($gateway['metadata']['namespace'] ?? '');
                    $name = (string) ($gateway['metadata']['name'] ?? '');
                    $owner = (string) ($gateway['metadata']['labels'][Manifests::APP_LABEL] ?? '');

                    if ($owner === (string) $app['id'] || !Manifests::is_tenant_namespace($namespace) || $name === '') {
                        continue;
                    }

                    self::delete_domain_gateway($runtime, $namespace, $name);
                    $log->warn("Deleted Gateway {$namespace}/{$name}: app #{$owner} no longer owns {$domain}");
                }
            } catch (Throwable $e) {
                $log->warn("Could not check other apps' Gateways for {$domain}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Delete a custom-domain Gateway and its certificate Secret. cert-manager
     * garbage-collects the Certificate (owned by the Gateway) but leaves the
     * Secret, private key included.
     */
    static function delete_domain_gateway(Runtime $runtime, string $namespace, string $name): void {
        $runtime->kube()->delete('gateway.networking.k8s.io/v1', 'Gateway', $namespace, $name);
        self::delete_domain_tls_secret($runtime->kube(), $namespace, $name);
    }

    /**
     * Delete a custom-domain Gateway's certificate Secret. cert-manager creates
     * it (labelled controller.cert-manager.io/fao=true, none of ours), and the
     * worker may only delete Secrets through a label-filtered deletecollection;
     * trongate-worker-owned-deletes allows exactly this name pattern with that
     * label.
     */
    static function delete_domain_tls_secret(Kubernetes $kube, string $namespace, string $gateway_name): void {
        $kube->delete_collection('v1', 'Secret', $namespace, 'controller.cert-manager.io/fao=true', 'metadata.name=' . Manifests::domain_tls_secret($gateway_name));
    }

    /**
     * Roll $deployment out and keep it only when it passes: the rollout must
     * finish (a new pod is ready only once its home page, or health check path, answers, see
     * Manifests::_deployment) and the smoke test must pass. Until the new pod
     * is ready the old one keeps serving. On failure the app's current
     * deployment, the last one that passed, is applied again (restore()), so
     * a failed deploy never stays live.
     *
     * $app comes back with the values that were applied (apply()).
     */
    static function roll_out(Runtime $runtime, Task_log $log, array &$app, array $deployment, array $database): bool {
        $current_id = (int) ($app['current_deployment_id'] ?? 0);

        self::apply($runtime, $log, $app, $deployment, $database);
        self::run_setup_sql($runtime, $log, (int) $app['id'], $database);

        if (self::wait_for_rollout($runtime, $log, $app) && self::smoke_test($runtime, $log, $app)) {
            return true;
        }

        self::restore($runtime, $log, $app, $current_id, (int) $deployment['id'], $database);

        return false;
    }

    /**
     * Run the SQL the app's database is set up with (app.setup_sql), once:
     * on the first deploy that reaches the rollout, right after apply()
     * created the database, so the app's tables are there before the smoke
     * test. A failure is recorded for the app page and logged, and the
     * deploy carries on.
     */
    static function run_setup_sql(Runtime $runtime, Task_log $log, int $app_id, array $database): void {
        $pending = self::row(
            $runtime->db(),
            "SELECT setup_sql FROM app WHERE id = ? AND setup_sql_status = 'pending'",
            [$app_id],
        );

        if ($pending === null) {
            return;
        }

        $statements = Setup_sql::plan((string) $pending['setup_sql'])['statements'];
        $log->info('Running the setup SQL: ' . count($statements) . (count($statements) === 1 ? ' statement' : ' statements'));
        $app_database = self::open_new_database($log, $database, $app_id);

        if ($app_database === null) {
            self::setup_sql_failed($runtime, $log, $app_id, 'The database was not ready, so none of it ran.', Setup_sql::join($statements));
            return;
        }

        $failure = $app_database->import($statements);

        if ($failure !== null) {
            $number = $failure['index'] + 1;
            $ran = $failure['index'] === 0 ? 'None of it ran.' : 'Statements 1 to ' . $failure['index'] . ' ran.';
            $message = "Statement {$number} of " . count($statements) . " failed: {$failure['error']} {$ran}";
            self::setup_sql_failed($runtime, $log, $app_id, $message, Setup_sql::join(array_slice($statements, $failure['index'])));
            return;
        }

        self::execute($runtime->db(), "UPDATE app SET setup_sql_status = 'done' WHERE id = ?", [$app_id]);
        $log->info('Ran the setup SQL');
    }

    /**
     * The new app's database, once mariadb-operator has created it and its
     * user, or null when it is not ready in time
     */
    static function open_new_database(Task_log $log, array $database, int $app_id): ?App_database {
        $last_error = '';

        for ($attempt = 1; $attempt <= self::DATABASE_READY_ATTEMPTS; $attempt++) {
            try {
                return App_database::open($database, $app_id);
            } catch (PDOException $e) {
                $last_error = App_database::error_message($e);
            }

            if ($attempt < self::DATABASE_READY_ATTEMPTS) {
                sleep(2);
            }
        }

        $log->warn('Could not open the database: ' . $last_error);

        return null;
    }

    static function setup_sql_failed(Runtime $runtime, Task_log $log, int $app_id, string $message, string $remaining_sql): void {
        self::execute(
            $runtime->db(),
            "UPDATE app SET setup_sql = ?, setup_sql_status = 'failed', setup_sql_error = ? WHERE id = ?",
            [$remaining_sql, $message, $app_id],
        );
        $log->warn("The setup SQL stopped. {$message} The deploy carries on; the app page shows the SQL that did not run.");
    }

    /**
     * Apply the app's last good deployment again after $failed_id failed, so
     * its pod (still running while the new one never got ready) stays the one
     * that serves. Nothing to restore on an app's first deploy.
     */
    static function restore(Runtime $runtime, Task_log $log, array &$app, int $current_id, int $failed_id, array $database): void {
        $current = $current_id > 0 && $current_id !== $failed_id
            ? self::row($runtime->db(), 'SELECT * FROM deployment WHERE id = ? AND app_id = ?', [$current_id, (int) $app['id']])
            : null;

        if ($current === null || empty($current['image'])) {
            $log->warn('No earlier deployment passed, so there is nothing to go back to');
            return;
        }

        $log->warn("Restoring deployment #{$current_id} ({$current['image']})");

        try {
            self::apply($runtime, $log, $app, $current, $database);
        } catch (Throwable $e) {
            $log->error("Restoring deployment #{$current_id} failed: " . $e->getMessage());
            return;
        }

        if (self::wait_for_rollout($runtime, $log, $app)) {
            $log->info("Deployment #{$current_id} is live again");
        } else {
            $log->error("Deployment #{$current_id} did not come back up; roll back from the app's Deployments");
        }
    }

    /**
     * Wait for the app Deployment to roll out, logging progress; on failure log why pods are unhappy
     */
    static function wait_for_rollout(Runtime $runtime, Task_log $log, array $app): bool {
        $kube = $runtime->kube();
        $namespace = Manifests::namespace_name($app);
        $name = Manifests::app_name((int) $app['id']);

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
     * Request the app's public URL (at its health check path, Manifests::health_path)
     * until it answers 200 with a page of its own.
     *
     * A 200 that is only the router's fallback text (FALLBACK_BODIES) does not
     * count, nor does Sablier's "starting" page: a sleeping app is woken by
     * this request and gets the next attempts to come up. Sablier answers 200
     * with that page, so any answer given while the app had no ready pod is
     * taken as Sablier's. On failure the last status and the start of the body
     * go to the deploy log.
     */
    static function smoke_test(Runtime $runtime, Task_log $log, array $app): bool {
        $url = rtrim(Trongate_config::base_url($app), '/') . Manifests::health_path($app);
        $kube = $runtime->kube();
        $namespace = Manifests::namespace_name($app);
        $name = Manifests::app_name((int) $app['id']);
        $log->info("Smoke testing {$url}");
        $last = '';

        try {
            $status = retry(self::SMOKE_TEST_ATTEMPTS, function () use ($url, $kube, $namespace, $name, &$last) {
                // Before the request: a pod that turns ready during it could still have answered
                $ready = (int) ($kube->get('apps/v1', 'Deployment', $namespace, $name)['status']['readyReplicas'] ?? 0);
                [$status, $body] = self::http_get($url);
                $problem = self::smoke_test_problem($status, $body)
                    ?? ($ready === 0 ? 'the app is still starting (no ready pod yet)' : null);

                if ($problem !== null) {
                    $last = $problem;
                    throw new RuntimeException($problem);
                }

                return $status;
            }, fn(int $attempt) => min(1000 * $attempt, 5000));
        } catch (RuntimeException) {
            $log->error('Smoke test failed after ' . self::SMOKE_TEST_ATTEMPTS . " attempts: {$last}");
            return false;
        }

        $log->info("Smoke test passed (HTTP {$status})");

        return true;
    }

    /**
     * Why a smoke test response is not the app, or null when it is
     */
    static function smoke_test_problem(int $status, string $body): ?string {
        $snippet = trim(preg_replace('/\s+/', ' ', mb_substr($body, 0, 200)) ?? '');
        $snippet = $snippet === '' ? '(empty body)' : '"' . $snippet . '"';

        if ($status === 0) {
            return 'no response: ' . $body;
        }

        if ($status !== 200) {
            return "HTTP {$status}, body {$snippet}";
        }

        if (in_array(strtolower(trim($body)), self::FALLBACK_BODIES, true)) {
            return "HTTP 200 but the router's fallback page, body {$snippet}";
        }

        if (stripos($body, 'sablier') !== false) {
            return 'the app is still starting (Sablier waiting page)';
        }

        return null;
    }

    /**
     * GET a URL following redirects: [final status, first 64 KiB of the body].
     * On a connection or TLS error the status is 0 and the body is curl's error.
     *
     * @return array{int, string}
     */
    static function http_get(string $url): array {
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'trongate.cloud smoke test',
            CURLOPT_HTTPHEADER => ['Accept: text/html,*/*'],
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body): int {
                $body .= $chunk;

                // Returning less than the chunk aborts the transfer once we have enough
                return strlen($body) > 65536 ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        $errno = curl_errno($curl);
        curl_close($curl);

        if ($ok === false && $errno !== CURLE_WRITE_ERROR) {
            return [0, $error];
        }

        return [$status, $body];
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
            self::log_pod_state($log, $pod);
        }
    }

    /**
     * Log why a pod is not running: each container that is waiting or has
     * failed (e.g. CreateContainerError when a node rejects its
     * securityContext), and a PodScheduled=False condition (no node fits).
     * Neither leaves a container log, so this is often the only trace.
     */
    static function log_pod_state(Task_log $log, ?array $pod): void {
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

    /**
     * Delete one of an app's objects, but only after checking that it carries
     * the app's ownership labels (Manifests::owned_by_app). The delete is
     * pinned to the checked object's uid, so a replacement is never removed.
     *
     * Secrets are write-only for the worker (worker-rbac.yaml) and cannot be
     * read first. They are deleted as a collection filtered by name and by the
     * same labels, so the API server does the label check: a Secret under that
     * name without the app's labels matches nothing and stays. The
     * admission policy trongate-worker-owned-deletes is a backstop for every
     * kind.
     *
     * @return bool false when the object was already gone (for a Secret: or is not the app's)
     * @throws RuntimeException when the object exists but is not the app's
     */
    static function delete_owned(Kubernetes $kube, string $api_version, string $kind, string $namespace, int $app_id, string $name): bool {
        if (!Manifests::is_tenant_namespace($namespace)) {
            throw new InvalidArgumentException("Not a tenant namespace: '{$namespace}'");
        }

        if ($api_version === 'v1' && $kind === 'Secret') {
            $labels = array_map(fn(string $key, string $value) => "{$key}={$value}", array_keys(Manifests::app_labels($app_id)), Manifests::app_labels($app_id));

            return $kube->delete_collection($api_version, $kind, $namespace, implode(',', $labels), 'metadata.name=' . $name) > 0;
        }

        $object = $kube->get($api_version, $kind, $namespace, $name);

        if ($object === null) {
            return false;
        }

        if (!Manifests::owned_by_app($object, $namespace, $app_id)) {
            throw new RuntimeException("{$kind} {$name} does not carry app #{$app_id}'s labels; left in place");
        }

        $uid = $object['metadata']['uid'] ?? null;

        return $kube->delete($api_version, $kind, $namespace, $name, is_string($uid) ? $uid : null);
    }

    /**
     * Start the build's own BuildKit daemon (Manifests::buildkitd; $kind 'd'
     * for a deployment, 'p' for a platform build) and return its buildctl
     * address once it is ready.
     */
    static function start_buildkitd(Runtime $runtime, Task_log $log, int $id, string $kind = 'd'): string {
        $kube = $runtime->kube();
        $name = Manifests::buildkitd_name($id, $kind);
        // Outlives the build step's own timeout, so the pod never ends mid-build
        $deadline_seconds = Runtime::SHELL_TIMEOUT_SECONDS + self::BUILDKITD_READY_SECONDS + 120;

        $log->info("Starting build daemon {$name}");
        $kube->apply(Manifests::buildkitd($id, $deadline_seconds, $kind));

        $until = time() + self::BUILDKITD_READY_SECONDS;
        $pod = null;

        while (time() < $until) {
            $pod = $kube->get('v1', 'Pod', Manifests::BUILDKIT_NAMESPACE, $name);
            $phase = (string) ($pod['status']['phase'] ?? '');
            $ip = (string) ($pod['status']['podIP'] ?? '');

            if ($phase === 'Failed' || $phase === 'Succeeded') {
                // The caller's finally deletes the pod, and its log with it
                self::log_pod_state($log, $pod);
                self::log_buildkitd($runtime, $log, $id, $kind);
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

        self::log_pod_state($log, $pod);
        self::log_buildkitd($runtime, $log, $id, $kind);
        throw new RuntimeException("Build daemon {$name} was not ready within " . self::BUILDKITD_READY_SECONDS . 's');
    }

    /**
     * Copy the end of the build daemon's log into the task log. When the
     * daemon itself fails (a container it cannot start), buildctl may only
     * show "exit code: 1"; the error is in the daemon's log, which goes with the pod in
     * stop_buildkitd. Call it on a failed build, before stop_buildkitd
     * (start_buildkitd already does when the daemon never becomes ready).
     * Never throws.
     */
    static function log_buildkitd(Runtime $runtime, Task_log $log, int $id, string $kind = 'd'): void {
        $name = Manifests::buildkitd_name($id, $kind);

        try {
            $lines = $runtime->kube()->pod_log(Manifests::BUILDKIT_NAMESPACE, $name, self::BUILDKITD_LOG_LINES);
        } catch (Throwable $e) {
            $log->warn("Could not read the log of build daemon {$name}: {$e->getMessage()}");
            return;
        }

        $log->info("Last lines of build daemon {$name}:");

        foreach (preg_split('/\R/', rtrim($lines)) ?: [] as $line) {
            if (trim($line) !== '') {
                $log->info('  ' . $line);
            }
        }
    }

    /**
     * Delete the build's BuildKit daemon. Never throws: a leftover pod
     * still ends at its activeDeadlineSeconds.
     */
    static function stop_buildkitd(Runtime $runtime, Task_log $log, int $id, string $kind = 'd'): void {
        $name = Manifests::buildkitd_name($id, $kind);

        try {
            $runtime->kube()->delete('v1', 'Pod', Manifests::BUILDKIT_NAMESPACE, $name);
        } catch (Throwable $e) {
            $log->warn("Could not delete build daemon {$name}: {$e->getMessage()}");
        }
    }

    /**
     * The buildctl command that builds a Dockerfile for linux/amd64 and pushes it
     * as $names, with a registry cache at $cache_ref; $opts are extra frontend
     * options (build args, labels).
     */
    static function buildctl_build(string $host, string $context, string $dockerfile_dir, string $filename, array $names, string $cache_ref, array $opts = []): string {
        $q = fn(string $value) => Trongate_config::sh_quote($value);

        return 'buildctl --addr ' . $q($host) . ' build'
            . ' --progress=plain'
            . ' --frontend=dockerfile.v0'
            . ' --local context=' . $q($context)
            . ' --local dockerfile=' . $q($dockerfile_dir)
            . ' --opt filename=' . $q($filename)
            . ' --opt platform=linux/amd64'
            . implode('', array_map(fn(string $opt) => ' --opt ' . $q($opt), $opts))
            . ' --import-cache ' . $q("type=registry,ref={$cache_ref}")
            . ' --export-cache ' . $q("type=registry,ref={$cache_ref},mode=max,ignore-error=true")
            . ' --output ' . $q('type=image,"name=' . implode(',', $names) . '",push=true');
    }

    /**
     * An image reference without its tag
     */
    static function image_repository(string $ref): string {
        return preg_replace('/:[^:\/]+$/', '', $ref);
    }

    /**
     * The buildctl command that builds docker/app-base from $context (a checkout
     * of this repository) and pushes it as PLATFORM_APP_BASE_IMAGE (and $extra_names).
     * SOURCE_DATE_EPOCH=0 and no revision label: an unchanged base keeps its
     * digest, so the apps' build caches stay valid.
     */
    static function app_base_build(string $host, string $context, array $extra_names = []): string {
        $repository = self::image_repository(PLATFORM_APP_BASE_IMAGE);

        return self::buildctl_build(
            $host,
            $context,
            $context,
            'docker/app-base/Dockerfile',
            [PLATFORM_APP_BASE_IMAGE, ...$extra_names],
            "{$repository}:buildcache",
            ['build-arg:SOURCE_DATE_EPOCH=0']
        );
    }

    /**
     * Make sure PLATFORM_APP_BASE_IMAGE can be pulled before an app is built on
     * it. build_platform normally pushes it, but the worker that runs a platform
     * build is the previous release, which may not know about the base yet (the
     * first rollout of it), and a registry can lose it. When it is missing, it
     * is built from the copy of docker/app-base in this worker's image.
     */
    static function ensure_app_base(Runtime $runtime, Task_log $log, string $host, string $work): bool {
        $q = fn(string $value) => Trongate_config::sh_quote($value);
        $probe = "{$work}/base-probe";

        if (!is_dir($probe) && !mkdir($probe, 0700, true)) {
            throw new RuntimeException('Could not create the build directory');
        }

        file_put_contents("{$probe}/Dockerfile", 'FROM ' . PLATFORM_APP_BASE_IMAGE . "\n");

        // Resolving the FROM is all this does: no output, nothing pushed
        $resolve = 'buildctl --addr ' . $q($host) . ' build --progress=plain'
            . ' --frontend=dockerfile.v0'
            . ' --local context=' . $q($probe)
            . ' --local dockerfile=' . $q($probe)
            . ' --opt platform=linux/amd64 >/dev/null 2>&1';

        if ($runtime->shell($resolve, $log) === 0) {
            return true;
        }

        $log->warn('App base image ' . PLATFORM_APP_BASE_IMAGE . ' is not in the registry; building it first');

        return $runtime->shell(self::app_base_build($host, dirname(__DIR__, 4)) . "\n", $log) === 0;
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
