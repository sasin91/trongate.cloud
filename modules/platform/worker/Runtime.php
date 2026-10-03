<?php

// Framework-free code shared with the app (see docs/agents.md, C3)
require_once APPPATH . 'modules/encryption/Encryptor.php';
require_once APPPATH . 'modules/services/clients/Client.php';
require_once APPPATH . 'modules/services/clients/Github.php';
require_once APPPATH . 'modules/services/clients/Kubernetes.php';
require_once APPPATH . 'modules/platform/manifests/Trongate_config.php';
require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once __DIR__ . '/Module_reference.php';
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
 * ## Why mysqli over PDO
 *
 * This worker is MariaDB-only and framework-free. mysqli is the native driver —
 * lighter than PDO, no abstraction layer needed.
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

    /** A shell step (e.g. a customer build) is killed after this long */
    const SHELL_TIMEOUT_SECONDS = 1800;

    /**
     * The only worker environment variables shell steps inherit. Secrets
     * (APP_KEY, DB_PASSWORD, KUBE_TOKEN, ...) stay out of tar/railpack/buildctl,
     * which handle untrusted repositories. Scripts export what else they need.
     */
    const SHELL_ENV = ['PATH', 'HOME', 'TMPDIR', 'LANG', 'DOCKER_CONFIG', 'RAILPACK_VERSION', 'RAILPACK_FRONTEND'];

    const TASKS_DIR = __DIR__ . '/../tasks';

    /** Deployment statuses that are final */
    const TERMINAL_STATUSES = ['success', 'failed', 'rolled_back'];

    const DEPLOYMENT_STATUSES = ['pending', 'building', 'deploying', 'success', 'failed', 'rolled_back'];

    public bool $running = false;

    private mysqli $db;

    private Module_reference $module_ref;

    private ?Kubernetes $kube = null;

    function __construct(mysqli $db) {
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
     * @throws mysqli_sql_exception when the connection fails
     */
    static function connect(): mysqli {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $db = new mysqli(
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_USER') ?: 'root',
            getenv('DB_PASSWORD') ?: '',
            getenv('DB_NAME') ?: 'tcloud',
            (int) (getenv('DB_PORT') ?: 3306)
        );
        $db->set_charset('utf8mb4');

        return $db;
    }

    // -------------------------------------------------------------------------
    // Public API for task scripts
    // -------------------------------------------------------------------------

    /**
     * The worker's database connection.
     */
    function db(): mysqli {
        return $this->db;
    }

    /**
     * Kubernetes API client, built once from the environment.
     */
    function kube(): Kubernetes {
        return $this->kube ??= Kubernetes::from_env();
    }

    /**
     * Resolve a module reference to its value.
     * Example: secrets:team,12,github
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
     * project's current deployment.
     */
    function set_deployment_status(int $id, string $status, array $extra = []): void {
        if (!in_array($status, self::DEPLOYMENT_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown deployment status: {$status}");
        }

        $sets = ['status = ?'];
        $types = 's';
        $values = [$status];

        foreach (['commit_sha', 'image', 'completed_at'] as $column) {
            if (array_key_exists($column, $extra)) {
                $sets[] = "{$column} = ?";
                $types .= 's';
                $values[] = $extra[$column] === null ? null : (string) $extra[$column];
            }
        }

        if (in_array($status, self::TERMINAL_STATUSES, true) && !array_key_exists('completed_at', $extra)) {
            $sets[] = 'completed_at = NOW()';
        }

        $types .= 'i';
        $values[] = $id;

        $stmt = $this->db->prepare('UPDATE deployment SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();

        if ($status === 'success') {
            $stmt = $this->db->prepare(
                'UPDATE project p
                 JOIN deployment d ON d.project_id = p.id
                 SET p.current_deployment_id = d.id
                 WHERE d.id = ?'
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
    }

    // -------------------------------------------------------------------------
    // Poll loop
    // -------------------------------------------------------------------------

    /**
     * Recover tasks left behind by a killed worker and fail their deployments.
     */
    function recover_stale_tasks(): void {
        $stmt = $this->db->prepare(
            'UPDATE tasks
             SET failed_at = NOW(),
                 error_message = ?
             WHERE started_at < NOW() - INTERVAL ? SECOND
               AND completed_at IS NULL
               AND failed_at IS NULL'
        );

        $message = 'Worker was terminated (exceeded termination grace period)';
        $threshold = self::STALE_THRESHOLD_SECONDS;
        $stmt->bind_param('si', $message, $threshold);
        $stmt->execute();

        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            $this->log("Recovered {$affected} stale task(s)");

            // Deployments whose task was killed would otherwise never finish
            $this->db->query(
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
            $task = $this->pick_task();

            if ($task === null) {
                sleep(self::POLL_INTERVAL_SECONDS);
                continue;
            }

            $this->log(sprintf('Picked task #%d (team_id=%d, script=%s)', $task['id'], $task['team_id'], $task['script']));
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
            $this->db->close();
        } catch (\Throwable) {
        }

        try {
            $this->db = self::connect();
        } catch (\mysqli_sql_exception $e) {
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
            $this->db->begin_transaction();
        } catch (\mysqli_sql_exception) {
            // Connection dead — errno 2006/2013
            if (!$this->reconnect()) {
                return null;
            }

            try {
                $this->db->begin_transaction();
            } catch (\mysqli_sql_exception) {
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

            $task = $result->fetch_assoc();
            $result->free();

            if ($task === null) {
                $this->db->rollback();
                return null;
            }

            $stmt = $this->db->prepare('UPDATE tasks SET started_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $task['id']);
            $stmt->execute();
            $stmt->close();

            $this->db->commit();
        } catch (\mysqli_sql_exception $e) {
            $this->log("Picking a task failed: {$e->getMessage()}");
            try {
                $this->db->rollback();
            } catch (\Throwable) {
            }
            return null;
        }

        $task['id'] = (int) $task['id'];
        $task['team_id'] = (int) $task['team_id'];
        $task['project_id'] = $task['project_id'] === null ? null : (int) $task['project_id'];
        $task['deployment_id'] = $task['deployment_id'] === null ? null : (int) $task['deployment_id'];

        return $task;
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
            $this->fail($task, $e->getMessage());
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
            $stmt = $this->db->prepare('UPDATE tasks SET completed_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $task['id']);
            $stmt->execute();
            $stmt->close();

            // Scripts normally set the final status; a still-open deployment succeeded
            if ($task['deployment_id'] !== null && !$this->deployment_is_terminal($task['deployment_id'])) {
                $this->set_deployment_status($task['deployment_id'], 'success');
            }
        });
    }

    private function fail(array $task, string $message): void {
        $this->run_safely(function () use ($task, $message) {
            $stmt = $this->db->prepare(
                'UPDATE tasks SET failed_at = NOW(), error_message = ? WHERE id = ?'
            );
            $stmt->bind_param('si', $message, $task['id']);
            $stmt->execute();
            $stmt->close();

            if ($task['deployment_id'] !== null && !$this->deployment_is_terminal($task['deployment_id'])) {
                $this->set_deployment_status($task['deployment_id'], 'failed');
            }
        });
    }

    private function deployment_is_terminal(int $deployment_id): bool {
        $stmt = $this->db->prepare('SELECT status FROM deployment WHERE id = ?');
        $stmt->bind_param('i', $deployment_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null || in_array($row['status'], self::TERMINAL_STATUSES, true);
    }

    /**
     * Bookkeeping must not kill the poll loop; a dead connection leaves the
     * task to stale recovery.
     */
    private function run_safely(Closure $callback): void {
        try {
            $callback();
        } catch (\mysqli_sql_exception $e) {
            $this->log("Task bookkeeping failed: {$e->getMessage()}");
        }
    }
}
