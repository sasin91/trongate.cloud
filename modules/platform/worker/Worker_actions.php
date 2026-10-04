<?php

require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once __DIR__ . '/Worker_db.php';

/**
 * Platform_actions for the worker, so a task that queues a deploy follows the
 * same rules as the UI, webhooks and MCP (one queued deploy per app,
 * trigger and commit checks). Only the queueing and read methods make sense
 * here; the domain and webhook-secret helpers need APP_KEY from config.php.
 *
 * @property Worker_db $db (the trait documents the framework Db)
 */
class Worker_actions {
    use Platform_actions;

    public Worker_db $db;

    function __construct(mysqli $db) {
        $this->db = new Worker_db($db);
    }
}
