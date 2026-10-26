<?php

require_once APPPATH . 'modules/log/Log_handler.php';

/**
 * Writes a task's log lines into task_logs, where the panel and the MCP
 * stream them from (Task_log).
 */
final class Task_log_handler implements Log_handler {

    function __construct(private PDO $db, private int $task_id) {
    }

    function handle(string $level, string $message): void {
        $sql = <<<SQL
            INSERT INTO task_logs (task_id, level, message) VALUES (?, ?, ?)
        SQL;
        Runtime::run($this->db, $sql, [$this->task_id, $level, $message]);
    }
}
