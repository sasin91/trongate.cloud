<?php

require_once __DIR__ . '/../DSL/Module_reference.php';

/**
 * Task Worker Runtime
 *
 * Polls MariaDB for pending server_tasks and executes them one at a time.
 *
 * ## Why mysqli over PDO
 *
 * This worker is MariaDB-only. mysqli is the native MariaDB/MySQL driver —
 * lighter than PDO, no abstraction layer needed. PDO's database-agnostic
 * interface adds overhead we don't benefit from since we'll never swap
 * the backing database.
 *
 * ## Graceful Shutdown
 *
 * On SIGTERM (sent by Docker during deploy/restart), the worker stops polling
 * and lets the current task's spawned process finish naturally. The Docker
 * `stop_grace_period` (see docker-compose.yml) controls how long the container
 * has before a SIGKILL. The stale task recovery threshold (STALE_THRESHOLD_SECONDS)
 * MUST match or be less than the stop_grace_period — if a task's started_at is
 * older than that threshold and still incomplete, we know Docker killed the worker.
 *
 * @see docker-compose.yml stop_grace_period
 */
class Runtime
{
    /**
     * Must match docker-compose.yml stop_grace_period.
     * Tasks running longer than this after SIGTERM are considered stale on next startup.
     */
    const STALE_THRESHOLD_SECONDS = 300;

    const POLL_INTERVAL_SECONDS = 2;

    const SCRIPTS_DIR = __DIR__ . '/../scripts';

    public bool $running = false;

    private mysqli $db;

    private Module_reference $module_ref;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->module_ref = new Module_reference($db);

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            $this->running = false;
        });
        pcntl_signal(SIGINT, function () {
            $this->running = false;
        });
    }

    /**
     * Log a message to stdout.
     */
    private function log(string $message): void
    {
        fwrite(STDOUT, '[Runtime] ' . $message . PHP_EOL);
    }

    /**
     * Recover tasks left behind by a killed worker.
     *
     * If a task has started_at set but no completed_at or failed_at, and it's
     * older than STALE_THRESHOLD_SECONDS, the previous worker was force-killed
     * by Docker after the stop_grace_period expired.
     *
     * @see docker-compose.yml stop_grace_period — must match STALE_THRESHOLD_SECONDS
     */
    public function recover_stale_tasks(): void
    {
        $stmt = $this->db->prepare(
            'UPDATE server_tasks
             SET failed_at = NOW(),
                 error_message = ?
             WHERE started_at < NOW() - INTERVAL ? SECOND
               AND completed_at IS NULL
               AND failed_at IS NULL'
        );

        $message = 'Worker was terminated (exceeded stop_grace_period)';
        $threshold = self::STALE_THRESHOLD_SECONDS;
        $stmt->bind_param('si', $message, $threshold);
        $stmt->execute();

        $affected = $stmt->affected_rows;
        if ($affected > 0) {
            $this->log("Recovered {$affected} stale task(s)");
        }

        $stmt->close();
    }

    public function poll(): void
    {
        $this->running = true;
        $this->log('Poll loop started, waiting for tasks...');

        while ($this->running) {
            $task = $this->pick_task();

            if ($task === null) {
                sleep(self::POLL_INTERVAL_SECONDS);
                continue;
            }

            $this->log(sprintf('Picked task #%d (server_id=%d, script=%s)', $task['id'], $task['server_id'], $task['script']));
            $this->execute($task);
        }

        $this->log('Poll loop stopped');
    }

    /**
     * Reconnect to the database.
     *
     * Called when a query fails due to a dead connection (e.g. MariaDB restarted).
     * Returns true if reconnection succeeded.
     */
    private function reconnect(): bool
    {
        $this->log('Database connection lost, reconnecting...');

        try {
            $this->db->close();
        } catch (\Throwable) {}

        $this->db = new mysqli(
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_USER') ?: 'root',
            getenv('DB_PASSWORD') ?: '',
            getenv('DB_NAME') ?: 'tcloud',
            (int) (getenv('DB_PORT') ?: 3306)
        );

        if ($this->db->connect_errno) {
            $this->log("Reconnect failed: {$this->db->connect_error}");
            return false;
        }

        return true;
    }

    /**
     * Atomically pick the oldest pending task.
     *
     * Uses FOR UPDATE SKIP LOCKED so multiple workers (if scaled)
     * never grab the same task.
     *
     * @return array|null The task row, or null if no pending tasks.
     */
    private function pick_task(): ?array
    {
        try {
            $this->db->begin_transaction();
        } catch (\mysqli_sql_exception $e) {
            // Connection dead (e.g. MariaDB restarted) — errno 2006/2013
            if (!$this->reconnect()) return null;

            try {
                $this->db->begin_transaction();
            } catch (\mysqli_sql_exception) {
                return null;
            }
        }

        $result = $this->db->query(
            'SELECT id, server_id, script, payload
             FROM server_tasks
             WHERE started_at IS NULL
               AND completed_at IS NULL
               AND failed_at IS NULL
             ORDER BY created_at
             LIMIT 1
             FOR UPDATE SKIP LOCKED'
        );

        $task = $result->fetch_assoc();
        $result->free();

        if ($task === null) {
            $this->db->rollback();
            return null;
        }

        $stmt = $this->db->prepare(
            'UPDATE server_tasks SET started_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('i', $task['id']);
        $stmt->execute();
        $stmt->close();

        $this->db->commit();

        return $task;
    }

    /**
     * Load a script, render it with payload variables, spawn it, and stream output.
     */
    private function execute(array $task): void
    {
        $script_path = self::SCRIPTS_DIR . '/' . basename($task['script']) . '.php';
        $this->log(sprintf('Executing task #%d with script: %s', $task['id'], $script_path));

        if (!file_exists($script_path)) {
            $this->log(sprintf('Script not found: %s', $script_path));
            $this->fail($task['id'], "Script not found: {$task['script']}", 1);
            return;
        }

        $payload = json_decode($task['payload'] ?? '{}', true);
        $this->log(sprintf('Task #%d payload: %s', $task['id'], json_encode($payload)));

        // The script file returns the rendered shell command/script
        // Pass $this so scripts can resolve secret references
        $runtime = $this;
        $rendered = $this->render_script($script_path, $payload, $task['server_id'], $runtime);

        if ($rendered === false) {
            $this->log(sprintf('Script render failed for task #%d', $task['id']));
            $this->fail($task['id'], "Script render failed: {$task['script']}", 1);
            return;
        }

        $this->log(sprintf('Rendered script for task #%d (length=%d bytes)', $task['id'], strlen($rendered)));
        $this->spawn($task['id'], $task['server_id'], $rendered);
    }

    /**
     * Resolve a module reference syntax to its actual value.
     * Example: secrets:server,123,db_project_1.db_password
     */
    public function resolve_reference(string $ref): mixed
    {
        return $this->module_ref->resolve($ref);
    }

    /**
     * Include the PHP script file, which has access to $payload, $server_id, and $runtime,
     * and returns the rendered shell script as a string.
     */
    private function render_script(string $script_path, array $payload, int $server_id, Runtime $runtime): string|false
    {
        try {
            ob_start();
            $result = include $script_path;
            ob_end_clean();

            return is_string($result) ? $result : false;
        } catch (\Throwable $e) {
            $buffered = ob_get_clean();
            $this->log('render_script error: ' . $e->getMessage());
            if ($buffered !== '') {
                $this->log('render_script output: ' . $buffered);
            }
            return false;
        }
    }

    /**
     * Spawn the rendered script as a process.
     * Streams stdout/stderr line-by-line into server_logs.
     */
    private function spawn(int $task_id, int $server_id, string $script): void
    {
        $this->log(sprintf('Spawning process for task #%d (server_id=%d)', $task_id, $server_id));

        $descriptors = [
            0 => ['pipe', 'r'],       // stdin
            1 => ['pipe', 'w'],       // stdout
            2 => ['pipe', 'w'],       // stderr
        ];

        $process = proc_open(['sh', '-c', $script], $descriptors, $pipes);

        if (!is_resource($process)) {
            $this->log(sprintf('Failed to spawn process for task #%d', $task_id));
            $this->fail($task_id, 'Failed to spawn process', 1);
            return;
        }

        $this->log(sprintf('Process spawned successfully for task #%d', $task_id));

        fclose($pipes[0]); // close stdin

        // Prepare log insert statement
        $log_stmt = $this->db->prepare(
            'INSERT INTO server_logs (server_id, level, message) VALUES (?, ?, ?)'
        );

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while (!empty($open)) {
            $read = array_values($open);
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 1);

            if ($changed === false) break;

            foreach ($open as $fd => $pipe) {
                $line = fgets($pipe);
                if ($line === false) {
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($open[$fd]);
                    }
                    continue;
                }

                $line = rtrim($line, "\n\r");
                if ($line === '') continue;

                $level = ($fd === 2) ? 'error' : 'info';
                $log_stmt->bind_param('iss', $server_id, $level, $line);
                $log_stmt->execute();
            }
        }

        $log_stmt->close();

        $exit_code = proc_close($process);

        if ($exit_code === 0) {
            $this->log(sprintf('Task #%d completed successfully', $task_id));
            $this->complete($task_id);
        } else {
            $this->log(sprintf('Task #%d failed with exit code %d', $task_id, $exit_code));
            $this->fail($task_id, "Process exited with code {$exit_code}", $exit_code);
        }
    }

    private function complete(int $task_id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE server_tasks SET completed_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('i', $task_id);
        $stmt->execute();
        $stmt->close();
    }

    private function fail(int $task_id, string $message, int $code, ?string $trace = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE server_tasks
             SET failed_at = NOW(), error_message = ?, error_code = ?, error_trace = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sisi', $message, $code, $trace, $task_id);
        $stmt->execute();
        $stmt->close();
    }
}
