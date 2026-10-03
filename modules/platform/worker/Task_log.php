<?php

/**
 * Task Log
 *
 * Writes log lines for one task into task_logs (streamed to the UI and MCP)
 * and mirrors them to STDOUT for the worker's container logs.
 */
class Task_log {
    const LEVELS = ['info', 'warn', 'error'];

    private mysqli $db;

    private int $task_id;

    function __construct(mysqli $db, int $task_id) {
        $this->db = $db;
        $this->task_id = $task_id;
    }

    function info(string $message): void {
        $this->write('info', $message);
    }

    function warn(string $message): void {
        $this->write('warn', $message);
    }

    function error(string $message): void {
        $this->write('error', $message);
    }

    function task_id(): int {
        return $this->task_id;
    }

    /**
     * Insert one row per line, so multi-line messages render like streamed output.
     */
    function write(string $level, string $message): void {
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        $lines = preg_split('/\r\n|\r|\n/', rtrim($message, "\r\n")) ?: [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            fwrite(STDOUT, sprintf('[task #%d] %s: %s', $this->task_id, $level, $line) . PHP_EOL);

            try {
                $stmt = $this->db->prepare(
                    'INSERT INTO task_logs (task_id, level, message) VALUES (?, ?, ?)'
                );
                $stmt->bind_param('iss', $this->task_id, $level, $line);
                $stmt->execute();
                $stmt->close();
            } catch (\mysqli_sql_exception $e) {
                // Never let logging break a running task
                fwrite(STDOUT, sprintf('[task #%d] log write failed: %s', $this->task_id, $e->getMessage()) . PHP_EOL);
            }
        }
    }
}
