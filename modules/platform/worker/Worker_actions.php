<?php

require_once APPPATH . 'engine/Trongate.php';
require_once APPPATH . 'modules/db/Db.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';

/**
 * Platform_actions for the worker, so a task that queues a deploy follows the
 * same rules as the UI, webhooks and MCP (one queued deploy per app,
 * trigger and commit checks). Only the queueing and read methods make sense
 * here; the domain and webhook-secret helpers need APP_KEY from config.php.
 *
 * $db is the framework's Db over the worker's own connection.
 *
 * @property Db $db (writable here; the trait declares it read-only)
 */
class Worker_actions {
    use Platform_actions;

    public Db $db;

    function __construct(PDO $db) {
        $this->db = self::framework_db($db);
    }

    /**
     * The framework's Db class over an existing PDO connection.
     *
     * Db's constructor opens its own connection from config/database.php and
     * calls block_url(), which needs the router, so it is skipped and the
     * connection is handed to Db's private handle instead. Outside its
     * constructor Db only needs $dbh; its other defaults (production error
     * messages, no debug output) are what the worker wants.
     */
    private static function framework_db(PDO $pdo): Db {
        $db = (new ReflectionClass(Db::class))->newInstanceWithoutConstructor();

        (function () use ($pdo) {
            $this->dbh = $pdo;
        })->call($db);

        return $db;
    }
}
