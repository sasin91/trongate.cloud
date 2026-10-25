<?php

/**
 * First step of an app's nightly backup Job (Manifests::daily_backup_job),
 * run from the worker image in the app's namespace: makes the staging folder
 * on the app's files volume (clearing what an earlier, stopped run left), then
 * dumps the app's database
 * (Database_dump, one consistent snapshot) to {STAGING_DIR}/database/database.sql.gz.
 *
 * Environment: DB_HOST, DB_PORT, DB_NAME (also the user), DB_PASSWORD, STAGING_DIR.
 */

require __DIR__ . '/../live/Database_dump.php';
require __DIR__ . '/Backup_job.php';

$staging = Backup_job::setting('STAGING_DIR');

if (is_dir($staging)) {
    Backup_job::empty_dir($staging);
}

// The app step mounts app/ as its /backup: made here, so it is the app user's
$database_dir = $staging . '/database';

foreach ([$staging, $database_dir, $staging . '/app'] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0700)) {
        Backup_job::stop("cannot create {$dir}");
    }
}

try {
    $name = Backup_job::setting('DB_NAME');
    $pdo = Database_dump::connect(Backup_job::setting('DB_HOST'), (int) Backup_job::setting('DB_PORT'), $name, $name, Backup_job::setting('DB_PASSWORD'));
    Database_dump::write($pdo, $name, $database_dir . '/database.sql.gz', [Backup_job::class, 'say']);
} catch (Throwable $e) {
    Backup_job::stop($e->getMessage());
}
