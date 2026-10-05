<?php

/**
 * Task worker entrypoint: php modules/platform/worker/server.php
 * Framework-free; configuration comes from the environment (see config/platform.php).
 */

// Repo root (modules/platform/worker -> root), matches the framework's APPPATH
defined('APPPATH') || define('APPPATH', dirname(__DIR__, 3) . '/');

require __DIR__ . '/Runtime.php';

// A new pod is briefly refused by the platform MariaDB: its NetworkPolicy
// admits the worker by pod label, and k3s's policy controller adds a new
// pod's IP to the allowed set a moment after the container starts. Retry
// for a while instead of crash-looping on the first refusal.
for ($attempt = 1; ; $attempt++) {
    try {
        $db = Runtime::connect();
        break;
    } catch (\mysqli_sql_exception $e) {
        if ($attempt >= 15) {
            error_log("DB connection failed: {$e->getMessage()}");
            exit(1);
        }

        error_log("DB connection attempt {$attempt} failed, retrying: {$e->getMessage()}");
        sleep(2);
    }
}

$runtime = new Runtime($db);
$runtime->recover_stale_tasks();
$runtime->poll();

$db->close();
