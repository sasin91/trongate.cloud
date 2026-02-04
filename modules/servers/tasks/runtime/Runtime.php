<?php

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

    public function __construct(mysqli $db)
    {
        $this->db = $db;

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            $this->running = false;
        });
        pcntl_signal(SIGINT, function () {
            $this->running = false;
        });
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
            error_log("Recovered {$affected} stale task(s)");
        }

        $stmt->close();
    }

    public function poll(): void
    {
        $this->running = true;

        while ($this->running) {
            $task = $this->pick_task();

            if ($task === null) {
                sleep(self::POLL_INTERVAL_SECONDS);
                continue;
            }

            $this->execute($task);
        }
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
        $this->db->begin_transaction();

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

        if (!file_exists($script_path)) {
            $this->fail($task['id'], "Script not found: {$task['script']}", 1);
            return;
        }

        $payload = json_decode($task['payload'] ?? '{}', true);

        // The script file returns the rendered shell command/script
        $rendered = $this->render_script($script_path, $payload, $task['server_id']);

        if ($rendered === false) {
            $this->fail($task['id'], "Script render failed: {$task['script']}", 1);
            return;
        }

        $this->spawn($task['id'], $task['server_id'], $rendered);
    }

    /**
     * Include the PHP script file, which has access to $payload and $server_id,
     * and returns the rendered shell script as a string.
     */
    private function render_script(string $script_path, array $payload, int $server_id): string|false
    {
        try {
            ob_start();
            $result = include $script_path;
            ob_end_clean();

            return is_string($result) ? $result : false;
        } catch (\Throwable $e) {
            ob_end_clean();
            error_log("render_script error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Spawn the rendered script as a process.
     * Streams stdout/stderr line-by-line into server_logs.
     */
    private function spawn(int $task_id, int $server_id, string $script): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],       // stdin
            1 => ['pipe', 'w'],       // stdout
            2 => ['pipe', 'w'],       // stderr
        ];

        $process = proc_open(['sh', '-c', $script], $descriptors, $pipes);

        if (!is_resource($process)) {
            $this->fail($task_id, 'Failed to spawn process', 1);
            return;
        }

        fclose($pipes[0]); // close stdin

        // Prepare log insert statement
        $log_stmt = $this->db->prepare(
            'INSERT INTO server_logs (server_id, level, message) VALUES (?, ?, ?)'
        );

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $read = [];
            if (is_resource($pipes[1])) $read[] = $pipes[1];
            if (is_resource($pipes[2])) $read[] = $pipes[2];

            if (empty($read)) break;

            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 1);

            if ($changed === false) break;

            foreach ($read as $pipe) {
                $line = fgets($pipe);
                if ($line === false) {
                    if (feof($pipe)) {
                        fclose($pipe);
                        // null out the closed pipe
                        if ($pipe === $pipes[1]) $pipes[1] = null;
                        if ($pipe === $pipes[2]) $pipes[2] = null;
                    }
                    continue;
                }

                $line = rtrim($line, "\n\r");
                if ($line === '') continue;

                $level = ($pipe === $pipes[2]) ? 'error' : 'info';
                $log_stmt->bind_param('iss', $server_id, $level, $line);
                $log_stmt->execute();
            }

            // Check if process has exited and pipes are drained
            if (!is_resource($pipes[1]) && !is_resource($pipes[2])) break;
        }

        $log_stmt->close();

        $exit_code = proc_close($process);

        if ($exit_code === 0) {
            $this->complete($task_id);
        } else {
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
