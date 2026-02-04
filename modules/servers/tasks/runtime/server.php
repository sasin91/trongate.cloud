<?php

require __DIR__ . '/Runtime.php';

$db = new mysqli(
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') ?: '',
    getenv('DB_NAME') ?: 'tcloud',
    (int) (getenv('DB_PORT') ?: 3306)
);

if ($db->connect_errno) {
    error_log("DB connection failed: {$db->connect_error}");
    exit(1);
}

$runtime = new Runtime($db);
$runtime->recover_stale_tasks();
$runtime->poll();

$db->close();
