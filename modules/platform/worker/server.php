<?php

/**
 * Task worker entrypoint: php modules/platform/worker/server.php
 * Framework-free; configuration comes from the environment (see config/platform.php).
 */

// Repo root (modules/platform/worker -> root), matches the framework's APPPATH
defined('APPPATH') || define('APPPATH', dirname(__DIR__, 3) . '/');

require __DIR__ . '/Runtime.php';

// Retry the first connection: a new pod's IP is refused by the platform MariaDB's
// NetworkPolicy until k3s's policy controller has synced it (about a second), and
// MariaDB itself may be restarting. 10 attempts, 250 ms doubling to 5 s (~28 s of waiting).
$attempts = 0;
$max_attempts = 10;
$sleep_ms = 250;

do {
    $attempts++;

    try {
        $db = Runtime::connect();
    } catch (\mysqli_sql_exception $e) {
        $db = null;
        error_log("DB connection failed (attempt {$attempts}/{$max_attempts}): {$e->getMessage()}");

        if ($attempts < $max_attempts) {
            usleep($sleep_ms * 1000);
            $sleep_ms = min($sleep_ms * 2, 5000);
        }
    }
} while ($db === null && $attempts < $max_attempts);

if ($db === null) {
    exit(1);
}

$runtime = new Runtime($db);
$runtime->recover_stale_tasks();
$runtime->poll();

$db->close();
