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
$max_attempts = 10;

try {
    $db = retry($max_attempts, fn() => Runtime::connect(), fn(int $attempt) => min(250 * 2 ** ($attempt - 1), 5000), function (Throwable $e, int $attempt) use ($max_attempts) {
        error_log("DB connection failed (attempt {$attempt}/{$max_attempts}): {$e->getMessage()}");

        return $e instanceof PDOException;
    });
} catch (PDOException $e) {
    error_log("DB connection failed (attempt {$max_attempts}/{$max_attempts}): {$e->getMessage()}");
    exit(1);
}

$runtime = new Runtime($db);
$runtime->recover_stale_tasks();
$runtime->poll();
