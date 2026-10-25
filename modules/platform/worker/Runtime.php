<?php

// Framework-free code shared with the app (see docs/agents.md, C3)
require_once APPPATH . 'modules/encryption/Encryptor.php';
require_once APPPATH . 'modules/services/clients/Client.php';
require_once APPPATH . 'modules/services/clients/Github.php';
require_once APPPATH . 'modules/services/clients/Github_app.php';
require_once APPPATH . 'modules/services/clients/Kubernetes.php';
require_once APPPATH . 'modules/services/clients/Valkey.php';
require_once APPPATH . 'modules/platform/manifests/Trongate_config.php';
require_once APPPATH . 'modules/platform/manifests/Config_error.php';
require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once APPPATH . 'modules/platform/manifests/Project_service.php';
require_once APPPATH . 'modules/platform/manifests/Domain_dns.php';
require_once __DIR__ . '/Module_reference.php';
require_once __DIR__ . '/retry.php';
require_once __DIR__ . '/Task_log.php';

/**
 * Task Worker Runtime
 *
 * Polls MariaDB for pending tasks and executes them one at a time.
 *
 * ## Task scripts
 *
 * modules/platform/tasks/{script}.php is included with:
 *   $payload - array decoded from tasks.payload
 *   $task    - array, the tasks row
 *   $runtime - this Runtime
 *
 * It returns either a shell script string (run via `sh -c`, every output line
 * logged) or a Closure(Task_log $log): int whose return value is the exit code
 * (0 = success). Any exception fails the task with its message.
 *
 * ## Periodic tasks
 *
 * PERIODIC_TASKS are platform-wide tasks (no project) that the poll loop queues
 * on an interval, e.g. reconcile_deploys. They run like any other task, so
 * they share its logging, failure handling and stale recovery. Adding one is
 * a line in PERIODIC_TASKS plus its script. The Runtime records when each
 * last finished and last failed (scheduled_jobs, exposed by /metrics); a
 * script may add a summary with record_periodic_summary().
 *
 * Each is claimed in scheduled_jobs with one conditional UPDATE, so with more
 * than one worker replica it is still queued once per interval, and never
 * while its previous run is unfinished; whichever worker is free runs it.
 *
 * ## Database
 *
 * The worker talks to MariaDB through PDO, like the web app. Its own
 * bookkeeping uses PDO directly (Runtime::run binds by value type, as the
 * framework's Db does); queueing deploys goes through the framework's Db
 * class over the same connection (see Worker_actions).
 *
 * ## Graceful Shutdown
 *
 * On SIGTERM the worker stops polling and lets the current task finish. The
 * container's termination grace period controls how long that may take before
 * a SIGKILL. STALE_THRESHOLD_SECONDS MUST match or be less than it — a task
 * started longer ago and still incomplete means the previous worker was killed.
 */
class Runtime {
    /**
     * Must match the worker's termination grace period.
     */
    const STALE_THRESHOLD_SECONDS = 300;

    const POLL_INTERVAL_SECONDS = 2;

    /**
     * Platform-wide tasks queued every N seconds (0 = off), as script => interval.
     * Add a job here and in modules/platform/tasks/{script}.php.
     */
    const PERIODIC_TASKS = [
        'reconcile_deploys' => DEPLOY_RECONCILE_INTERVAL,
        'schedule_domain_rechecks' => DOMAIN_RECHECK_INTERVAL,
        'sample_app_logs' => APP_LOG_SAMPLE_INTERVAL,
        'redeploy_outdated_apps' => APP_CONFIG_REDEPLOY_INTERVAL,
        'check_sql_imports' => SQL_IMPORT_CHECK_INTERVAL,
        'check_app_backups' => APP_BACKUP_CHECK_INTERVAL,
        'back_up_daily' => DAILY_BACKUP_INTERVAL,
    ];

    /** How often the poll loop looks for due periodic tasks */
    const SCHEDULE_CHECK_SECONDS = 30;

    /** Finished periodic task runs (and their logs) are kept this long */
    const PERIODIC_RETENTION_DAYS = 7;

    /** A shell step (e.g. a customer build) is killed after this long */
    const SHELL_TIMEOUT_SECONDS = 1800;

    /**
     * The only worker environment variables shell steps inherit. Secrets
     * (APP_KEY, DB_PASSWORD, KUBE_TOKEN, ...) stay out of tar/buildctl,
     * which handle untrusted repositories. Scripts export what else they need.
     */
    const SHELL_ENV = ['PATH', 'HOME', 'TMPDIR', 'LANG', 'DOCKER_CONFIG'];

    const TASKS_DIR = __DIR__ . '/../tasks';

    /** Deployment statuses that are final */
    const TERMINAL_STATUSES = ['success', 'failed', 'rolled_back'];

    const DEPLOYMENT_STATUSES = ['pending', 'building', 'deploying', 'success', 'failed', 'rolled_back'];

    public bool $running = false;

    private PDO $db;

    private Module_reference $module_ref;

    private ?Kubernetes $kube = null;
    private ?Valkey $valkey = null;

    private int $next_schedule_check = 0;

    function __construct(PDO $db) {
        $this->db = $db;
        $this->module_ref = new Module_reference($db);

        // pcntl is unavailable on Windows; there the worker just stops on Ctrl+C
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () {
                $this->running = false;
            });
            pcntl_signal(SIGINT, function () {
                $this->running = false;
            });
        }
    }

    /**
     * Open a database connection from the worker's environment.
     *
     * Not persistent: after a lost connection, reconnect() must get a new one.
     *
     * @throws PDOException when the connection fails
     */
    static function connect(): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: '127.0.0.1',
            (int) (getenv('DB_PORT') ?: 3306),
            getenv('DB_NAME') ?: 'tcloud'
        );

        return new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Prepare and execute SQL with positional (?) parameters. Each value is
     * bound by its PHP type, as the framework's Db does, so ints work in
     * LIMIT and null compares with <=>.
     */
    static function run(PDO $db, string $sql, array $params = []): PDOStatement {
        $stmt = $db->prepare($sql);

        foreach (array_values($params) as $i => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            $stmt->bindValue($i + 1, $value, $type);
        }

        $stmt->execute();

        return $stmt;
    }

    // -------------------------------------------------------------------------
    // Public API for task scripts
    // -------------------------------------------------------------------------

    /**
     * The worker's database connection.
     */
    function db(): PDO {
        return $this->db;
    }

    /**
     * Kubernetes API client, built once from the environment.
     */
    function kube(): Kubernetes {
        return $this->kube ??= Kubernetes::from_env();
    }

    /**
     * Admin connection to the shared Valkey (customer app sessions), or null
     * when the worker has no Valkey credentials (Valkey::configured()).
     */
    function valkey(): ?Valkey {
        if (!Valkey::configured()) {
            return null;
        }

        return $this->valkey ??= Valkey::from_env();
    }

    /**
     * Resolve a module reference to its value.
     * Example: secrets:customer,12,github (a customer's GitHub token)
     */
    function resolve_reference(string $ref): mixed {
        return $this->module_ref->resolve($ref);
    }

    /**
     * Run a shell script via `sh -c`, logging every stdout line as info and
     * every stderr line as warn (build tools report progress on stderr).
     *
     * GNU timeout kills the whole process group after $timeout seconds
     * (SIGTERM, then SIGKILL 30s later), so a build that never exits cannot
     * block the worker. The script only sees the SHELL_ENV variables.
     *
     * @return int The process exit code (124 or 137 on timeout)
     */
    function shell(string $script, Task_log $log, int $timeout = self::SHELL_TIMEOUT_SECONDS): int {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = ['sh', '-c', $script];
        $env = null; // Windows dev: sh needs the full environment, there is no GNU timeout

        if (PHP_OS_FAMILY !== 'Windows') {
            $command = ['timeout', '--kill-after=30', (string) $timeout, ...$command];
            $env = $this->_shell_env();
        }

        $process = proc_open($command, $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            $log->error('Failed to spawn process');
            return 1;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $buffers = [1 => '', 2 => ''];
        $levels = [1 => 'info', 2 => 'warn'];

        while (!empty($open)) {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($open as $fd => $pipe) {
                $chunk = fread($pipe, 8192);

                if ($chunk !== false && $chunk !== '') {
                    $buffers[$fd] .= $chunk;

                    // Log complete lines, keep the partial tail for the next read
                    while (($pos = strpos($buffers[$fd], "\n")) !== false) {
                        $log->write($levels[$fd], substr($buffers[$fd], 0, $pos));
                        $buffers[$fd] = substr($buffers[$fd], $pos + 1);
                    }
                }

                if (feof($pipe)) {
                    if ($buffers[$fd] !== '') {
                        $log->write($levels[$fd], $buffers[$fd]);
                        $buffers[$fd] = '';
                    }
                    fclose($pipe);
                    unset($open[$fd]);
                }
            }
        }

        foreach ($open as $pipe) {
            fclose($pipe);
        }

        $exit_code = proc_close($process);

        if (PHP_OS_FAMILY !== 'Windows' && ($exit_code === 124 || $exit_code === 137)) {
            $log->error("Step timed out after {$timeout} seconds");
        }

        return $exit_code;
    }

    /**
     * @return array<string, string> The SHELL_ENV subset of the worker's environment
     */
    function _shell_env(): array {
        $env = [];

        foreach (self::SHELL_ENV as $name) {
            $value = getenv($name);

            if ($value !== false) {
                $env[$name] = $value;
            }
        }

        $env['PATH'] ??= '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

        return $env;
    }

    /**
     * Update a deployment's status.
     *
     * $extra may set commit_sha, image and completed_at. Terminal statuses get
     * completed_at = NOW() unless given. On success the deployment becomes the
     * app's current deployment.
     */
    function set_deployment_status(int $id, string $status, array $extra = []): void {
        if (!in_array($status, self::DEPLOYMENT_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown deployment status: {$status}");
        }

        $sets = ['status = ?'];
        $values = [$status];

        foreach (['commit_sha', 'image', 'config_files', 'failure', 'completed_at'] as $column) {
            if (array_key_exists($column, $extra)) {
                $sets[] = "{$column} = ?";
                $values[] = $extra[$column] === null ? null : (string) $extra[$column];
            }
        }

        if (in_array($status, self::TERMINAL_STATUSES, true) && !array_key_exists('completed_at', $extra)) {
            $sets[] = 'completed_at = NOW()';
        }

        $values[] = $id;

        self::run($this->db, 'UPDATE deployment SET ' . implode(', ', $sets) . ' WHERE id = ?', $values);

        if ($status === 'success') {
            self::run(
                $this->db,
                'UPDATE app p
                 JOIN deployment d ON d.app_id = p.id
                 SET p.current_deployment_id = d.id
                 WHERE d.id = ?',
                [$id]
            );
        }
    }

    /**
     * Save a periodic task's run summary (exposed by /metrics). Optional: the
     * Runtime records when the task finished either way.
     */
    function record_periodic_summary(string $script, array $summary): void {
        self::run(
            $this->db,
            'UPDATE scheduled_jobs SET last_summary = ? WHERE script = ?',
            [json_encode($summary, JSON_UNESCAPED_SLASHES), $script]
        );
    }

    // -------------------------------------------------------------------------
    // Poll loop
    // -------------------------------------------------------------------------

    /**
     * Recover tasks left behind by a killed worker and fail their deployments.
     */
    function recover_stale_tasks(): void {
        $affected = self::run(
            $this->db,
            'UPDATE tasks
             SET failed_at = NOW(),
                 error_message = ?
             WHERE started_at < NOW() - INTERVAL ? SECOND
               AND completed_at IS NULL
               AND failed_at IS NULL',
            ['Worker was terminated (exceeded termination grace period)', self::STALE_THRESHOLD_SECONDS]
        )->rowCount();

        if ($affected > 0) {
            $this->log("Recovered {$affected} stale task(s)");

            // Deployments whose task was killed would otherwise never finish
            $this->db->exec(
                "UPDATE deployment d
                 JOIN tasks t ON t.deployment_id = d.id
                 SET d.status = 'failed', d.completed_at = NOW()
                 WHERE d.status NOT IN ('success', 'failed', 'rolled_back')
                   AND t.failed_at IS NOT NULL"
            );
        }
    }

    function poll(): void {
        $this->running = true;
        $this->log('Poll loop started, waiting for tasks...');

        while ($this->running) {
            $this->queue_due_periodic_tasks();

            $task = $this->pick_task();

            if ($task === null) {
                sleep(self::POLL_INTERVAL_SECONDS);
                continue;
            }

            $this->log(sprintf('Picked task #%d (project_id=%s, script=%s)', $task['id'], $task['project_id'] ?? '-', $task['script']));
            $this->execute($task);
        }

        $this->log('Poll loop stopped');
    }

    private function log(string $message): void {
        fwrite(STDOUT, '[Runtime] ' . $message . PHP_EOL);
    }

    /**
     * Reconnect after the connection died (e.g. MariaDB restarted).
     */
    private function reconnect(): bool {
        $this->log('Database connection lost, reconnecting...');

        try {
            $this->db = self::connect();
        } catch (\PDOException $e) {
            $this->log("Reconnect failed: {$e->getMessage()}");
            return false;
        }

        // Module_reference holds its own handle to the connection
        $this->module_ref = new Module_reference($this->db);

        return true;
    }

    /**
     * Atomically claim the oldest pending task.
     * FOR UPDATE SKIP LOCKED lets several workers run side by side.
     *
     * @return array|null The task row, or null if none is pending
     */
    private function pick_task(): ?array {
        try {
            $this->db->beginTransaction();
        } catch (\PDOException) {
            // Connection dead — errno 2006/2013
            if (!$this->reconnect()) {
                return null;
            }

            try {
                $this->db->beginTransaction();
            } catch (\PDOException) {
                return null;
            }
        }

        try {
            $result = $this->db->query(
                'SELECT *
                 FROM tasks
                 WHERE started_at IS NULL
                   AND completed_at IS NULL
                   AND failed_at IS NULL
                 ORDER BY created_at, id
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED'
            );

            $task = $result->fetch();
            $result->closeCursor();

            if ($task === false) {
                $this->db->rollBack();
                return null;
            }

            self::run($this->db, 'UPDATE tasks SET started_at = NOW() WHERE id = ?', [(int) $task['id']]);

            $this->db->commit();
        } catch (\PDOException $e) {
            $this->log("Picking a task failed: {$e->getMessage()}");
            try {
                $this->db->rollBack();
            } catch (\Throwable) {
            }
            return null;
        }

        $task['id'] = (int) $task['id'];
        $task['project_id'] = $task['project_id'] === null ? null : (int) $task['project_id'];
        $task['app_id'] = $task['app_id'] === null ? null : (int) $task['app_id'];
        $task['deployment_id'] = $task['deployment_id'] === null ? null : (int) $task['deployment_id'];

        return $task;
    }

    /**
     * Queue each periodic task whose interval has passed since it was last
     * queued. Checked every SCHEDULE_CHECK_SECONDS, between tasks.
     */
    private function queue_due_periodic_tasks(): void {
        if (time() < $this->next_schedule_check) {
            return;
        }

        $this->next_schedule_check = time() + self::SCHEDULE_CHECK_SECONDS;

        foreach (self::PERIODIC_TASKS as $script => $interval) {
            if ($interval <= 0) {
                continue;
            }

            try {
                if ($this->claim_periodic_task($script, $interval)) {
                    $this->log("Queued periodic task {$script}");
                }
            } catch (\PDOException $e) {
                // A dead connection is picked up by pick_task's reconnect
                $this->log("Scheduling {$script} failed: {$e->getMessage()}");
                try {
                    $this->db->rollBack();
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Queue the periodic task if it is due and no earlier run is unfinished.
     * The conditional UPDATE is the claim: of several workers, one wins.
     */
    private function claim_periodic_task(string $script, int $interval): bool {
        self::run($this->db, 'INSERT IGNORE INTO scheduled_jobs (script) VALUES (?)', [$script]);

        $this->db->beginTransaction();

        $claimed = self::run(
            $this->db,
            'UPDATE scheduled_jobs s
             SET s.last_queued_at = NOW()
             WHERE s.script = ?
               AND (s.last_queued_at IS NULL OR s.last_queued_at <= NOW() - INTERVAL ? SECOND)
               AND NOT EXISTS (
                   SELECT 1 FROM tasks t
                   WHERE t.script = s.script
                     AND t.completed_at IS NULL
                     AND t.failed_at IS NULL
               )',
            [$script, $interval]
        )->rowCount() === 1;

        if (!$claimed) {
            $this->db->rollBack();
            return false;
        }

        self::run($this->db, "INSERT INTO tasks (project_id, script, payload) VALUES (NULL, ?, '{}')", [$script]);

        // Failed runs too: scheduled_jobs.last_failed_at keeps the signal
        self::run(
            $this->db,
            'DELETE FROM tasks
             WHERE script = ?
               AND project_id IS NULL
               AND COALESCE(completed_at, failed_at) < NOW() - INTERVAL ? DAY',
            [$script, self::PERIODIC_RETENTION_DAYS]
        );

        $this->db->commit();

        return true;
    }

    /**
     * Include the task script and run what it returns.
     */
    private function execute(array $task): void {
        $log = new Task_log($this->db, $task['id']);
        $script_path = self::TASKS_DIR . '/' . basename($task['script']) . '.php';

        if (!preg_match('/^[a-z0-9_]+$/', $task['script']) || !file_exists($script_path)) {
            $log->error("Task script not found: {$task['script']}");
            $this->fail($task, "Task script not found: {$task['script']}");
            return;
        }

        try {
            $payload = json_decode($task['payload'] ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
            $result = $this->include_script($script_path, $payload, $task);

            if (is_string($result)) {
                $exit_code = $this->shell($result, $log);
            } elseif ($result instanceof Closure) {
                $exit_code = $result($log);
                if (!is_int($exit_code)) {
                    throw new UnexpectedValueException('Task closure must return an int exit code');
                }
            } else {
                throw new UnexpectedValueException('Task script must return a shell string or a Closure');
            }
        } catch (\Throwable $e) {
            $log->error($e->getMessage());
            $this->log(sprintf('Task #%d threw %s: %s', $task['id'], get_class($e), $e->getMessage()));
            $this->fail($task, $e->getMessage(), $e instanceof Config_error ? $e->to_array() : null);
            return;
        }

        if ($exit_code === 0) {
            $this->log(sprintf('Task #%d completed successfully', $task['id']));
            $this->complete($task);
        } else {
            $log->error("Process exited with code {$exit_code}");
            $this->log(sprintf('Task #%d failed with exit code %d', $task['id'], $exit_code));
            $this->fail($task, "Process exited with code {$exit_code}");
        }
    }

    /**
     * Include a task script with $payload, $task and $runtime in scope.
     * Stray output is discarded; the script communicates through its return value.
     */
    private function include_script(string $script_path, array $payload, array $task): mixed {
        $runtime = $this;

        ob_start();
        try {
            return include $script_path;
        } finally {
            ob_end_clean();
        }
    }

    private function complete(array $task): void {
        $this->run_safely(function () use ($task) {
            self::run($this->db, 'UPDATE tasks SET completed_at = NOW() WHERE id = ?', [$task['id']]);

            $this->record_periodic_run($task, 'last_finished_at');

            // Scripts normally set the final status; a still-open deployment succeeded
            if ($task['deployment_id'] !== null && !$this->deployment_is_terminal($task['deployment_id'])) {
                $this->set_deployment_status($task['deployment_id'], 'success');
            }
        });
    }

    /**
     * @param ?array $failure what the deployment page shows about it (Config_error::to_array)
     */
    private function fail(array $task, string $message, ?array $failure = null): void {
        $this->run_safely(function () use ($task, $message, $failure) {
            self::run($this->db, 'UPDATE tasks SET failed_at = NOW(), error_message = ? WHERE id = ?', [$message, $task['id']]);

            $this->record_periodic_run($task, 'last_failed_at');

            if ($task['deployment_id'] !== null && !$this->deployment_is_terminal($task['deployment_id'])) {
                $this->set_deployment_status($task['deployment_id'], 'failed', $failure !== null ? ['failure' => json_encode($failure)] : []);
            }
        });
    }

    /**
     * Stamp a periodic (project-less) task's finish or failure in scheduled_jobs.
     *
     * @param 'last_finished_at'|'last_failed_at' $column
     */
    private function record_periodic_run(array $task, string $column): void {
        if ($task['project_id'] !== null || !isset(self::PERIODIC_TASKS[$task['script']])) {
            return;
        }

        self::run($this->db, "UPDATE scheduled_jobs SET {$column} = NOW() WHERE script = ?", [$task['script']]);
    }

    private function deployment_is_terminal(int $deployment_id): bool {
        $row = self::run($this->db, 'SELECT status FROM deployment WHERE id = ?', [$deployment_id])->fetch();

        return $row === false || in_array($row['status'], self::TERMINAL_STATUSES, true);
    }

    /**
     * Bookkeeping must not kill the poll loop; a dead connection leaves the
     * task to stale recovery.
     */
    private function run_safely(Closure $callback): void {
        try {
            $callback();
        } catch (\PDOException $e) {
            $this->log("Task bookkeeping failed: {$e->getMessage()}");
        }
    }
}
