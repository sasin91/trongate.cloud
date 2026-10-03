<?php

/**
 * Task worker entrypoint: php modules/platform/worker/server.php
 * Framework-free; configuration comes from the environment (see config/platform.php).
 */

// Repo root (modules/platform/worker -> root), matches the framework's APPPATH
defined('APPPATH') || define('APPPATH', dirname(__DIR__, 3) . '/');

require __DIR__ . '/Runtime.php';

try {
    $db = Runtime::connect();
} catch (\mysqli_sql_exception $e) {
    error_log("DB connection failed: {$e->getMessage()}");
    exit(1);
}

$runtime = new Runtime($db);
$runtime->recover_stale_tasks();
$runtime->poll();

$db->close();
