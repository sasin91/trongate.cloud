<?php

require_once APPPATH . 'modules/cli/Log.php';
require_once __DIR__ . '/Task_log_handler.php';

/**
 * Task Log
 *
 * The log of one task (modules/cli Log): its lines go into task_logs
 * (Task_log_handler, streamed to the UI and MCP) and to STDOUT for the
 * worker's container logs. A line that cannot be written to task_logs still
 * reaches STDOUT, and never breaks the running task.
 */
class Task_log extends Log {
    const LEVELS = ['info', 'warn', 'error', 'step'];

    function __construct(PDO $db, private int $task_id) {
        parent::__construct([
            new Stream_handler("[task #{$task_id}] "),
            new Task_log_handler($db, $task_id),
        ]);
    }

    /**
     * Mark where the task enters one of a deploy's steps (Deploy_steps::KEYS):
     * the deployment page shows the lines after it under that step
     */
    function step(string $key): void {
        $this->write('step', $key);
    }

    function task_id(): int {
        return $this->task_id;
    }
}
