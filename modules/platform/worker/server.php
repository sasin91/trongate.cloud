<?php

/**
 * Task worker entrypoint: php modules/platform/worker/server.php
 * Framework-free; configuration comes from the environment (see config/platform.php).
 */

// Repo root (modules/platform/worker -> root), matches the framework's APPPATH
defined('APPPATH') || define('APPPATH', dirname(__DIR__, 3) . '/');

require __DIR__ . '/Runtime.php';
require __DIR__ . '/Migrations.php';

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

// This rollout's schema changes (db/migrations), before any task runs. A
// failing migration stops the worker; the pod restarts and retries it.
try {
    foreach (Migrations::apply($db) as $name) {
        error_log("Applied migration {$name}");
    }
} catch (RuntimeException $e) {
    error_log($e->getMessage());
    exit(1);
}

$runtime = new Runtime($db);

// The panel's Valkey session user (Valkey_sessions::start_platform). New panel
// pods wait for it in their startup probe (bin/check-sessions.php). Retried
// like the DB connection, since Valkey may be restarting; a failure is logged
// and the worker carries on, so builds and deploys still run.
if ($valkey = $runtime->valkey()) {
    try {
        retry(5, fn() => Valkey_sessions::set_user($valkey, Valkey_sessions::PLATFORM_USER), fn(int $attempt) => min(500 * 2 ** ($attempt - 1), 5000));
        error_log('Panel session store ready (Valkey user ' . Valkey_sessions::PLATFORM_USER . ')');
    } catch (Throwable $e) {
        error_log("Panel session store failed: {$e->getMessage()}");
    }
} else {
    error_log('Valkey is not configured for the worker; panel sessions will not work');
}

$runtime->recover_stale_tasks();
$runtime->poll();
