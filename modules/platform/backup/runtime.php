<?php

/**
 * The runtime of an app's nightly backup Job (Manifests::daily_backup_job).
 * A backup is a pipeline of actions, run in order:
 *
 *   php runtime.php dump      worker image: make the staging folder, dump the database
 *   (the app step)            the app's own image: pack /app, then run the app's own
 *                             backup module (Manifests::daily_backup_app_script)
 *   php runtime.php upload    worker image: pack, encrypt and upload, then clean up
 *
 * The app step runs from the app's image, which has none of this code, so
 * the Job runs each part in its own container. The staging folder sits on the
 * app's files volume and then holds:
 *
 *   database/database.sql.gz   the app's database (Database_dump, one consistent snapshot)
 *   app/app.tar                /app as the app sees it: its code, config and
 *                              persistent (uploaded) files
 *   app/custom/                what the app's own modules/trongate-cloud/backup/runtime.php
 *                              wrote, when it has one (docs/backups.md)
 *   app/custom.log             that script's output
 *
 * Each action logs to the Job's log; a failing one exits 1, which fails the
 * Job. An upload that fails still empties the staging folder, so no copy of
 * the data is left behind.
 *
 * Environment: STAGING_DIR; for dump DB_HOST, DB_PORT, DB_NAME (also the
 * user) and DB_PASSWORD; for upload BACKUP_PUBLIC_KEY and UPLOAD_URL.
 */

require __DIR__ . '/../../cli/Log.php';
require __DIR__ . '/../../cli/Env.php';
require __DIR__ . '/../../cli/Command.php';
require __DIR__ . '/../../cli/Pipeline.php';
require __DIR__ . '/../live/Database_dump.php';
require __DIR__ . '/../live/Backup_file.php';
require __DIR__ . '/../live/Object_storage.php';

$log = new Log([new Stream_handler('', false)]);

try {
    $staging = Env::required('STAGING_DIR');
} catch (RuntimeException $e) {
    $log->error($e->getMessage());
    exit(1);
}

$archive = "{$staging}/backup.tar.gz";

// Removes everything inside the staging folder, keeping the folder
$empty_staging = fn() => Command::run(['find', $staging, '-mindepth', '1', '-delete']);

$make_staging = function () use ($staging, $empty_staging): void {
    if (is_dir($staging)) {
        $empty_staging();
    }

    // The app step mounts app/ as its /backup: made here, so it is the app user's
    foreach ([$staging, "{$staging}/database", "{$staging}/app"] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0700)) {
            throw new RuntimeException("cannot create {$dir}");
        }
    }
};

$dump_database = function () use ($staging, $log): void {
    $name = Env::required('DB_NAME');
    $pdo = Database_dump::connect(Env::required('DB_HOST'), (int) Env::required('DB_PORT'), $name, $name, Env::required('DB_PASSWORD'));
    Database_dump::write($pdo, $name, "{$staging}/database/database.sql.gz", [$log, 'info']);
};

$check_parts = function () use ($staging): void {
    foreach (['database/database.sql.gz', 'app/app.tar'] as $part) {
        if (!is_file("{$staging}/{$part}")) {
            throw new RuntimeException("{$part} is missing; an earlier step did not finish");
        }
    }
};

// The worker reads this line from the log (Daily_backup_run::module_failure)
$note_module_failure = function () use ($staging, $log): void {
    if (is_file("{$staging}/app/custom.failed")) {
        $why = str_replace("\n", ' / ', trim((string) file_get_contents("{$staging}/app/custom.failed")));
        $log->info("runtime.php failed; the backup has everything else: {$why}");
    }
};

$pack = fn() => Command::run(['tar', '-czf', $archive, '-C', $staging, 'database', 'app']);

$encrypt = function () use ($archive): void {
    Backup_file::encrypt($archive, "{$archive}.enc", Env::required('BACKUP_PUBLIC_KEY'));
    unlink($archive);
};

$upload = function () use ($archive, $log): void {
    $bytes = Object_storage::upload("{$archive}.enc", Env::required('UPLOAD_URL'));
    $log->info("Uploaded the backup ({$bytes} bytes encrypted)");
};

$pipeline = new Pipeline($log, [
    'dump' => [$make_staging, $dump_database],
    'upload' => [$check_parts, $note_module_failure, $pack, $encrypt, $upload, $empty_staging],
], function (string $name) use ($empty_staging): void {
    if ($name === 'upload') {
        $empty_staging();
    }
});

exit($pipeline->run($argv[1] ?? ''));
