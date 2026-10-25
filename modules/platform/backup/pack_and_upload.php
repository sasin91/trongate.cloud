<?php

/**
 * Last step of an app's nightly backup Job (Manifests::daily_backup_job), run
 * from the worker image in the app's namespace once the database dump
 * (dump_database.php) and the app's own step (Manifests::DAILY_BACKUP_APP_SCRIPT)
 * are done. The staging folder then holds:
 *
 *   database/database.sql.gz   the app's database
 *   app/app.tar                /app as the app sees it: its code, config and
 *                              persistent (uploaded) files
 *   app/custom/                what the app's own modules/trongate-cloud/backup/runtime.php
 *                              wrote, when it has one (docs/backups.md)
 *   app/custom.log             that script's output
 *
 * It packs all of it into backup.tar.gz, encrypts that to the platform's
 * public key (Backup_file), uploads it to the signed link it was given, and
 * empties the staging folder, which sits on the app's files volume.
 *
 * Environment: STAGING_DIR, BACKUP_PUBLIC_KEY, UPLOAD_URL.
 */

require __DIR__ . '/../live/Backup_file.php';
require __DIR__ . '/../live/Object_storage.php';
require __DIR__ . '/Backup_job.php';

$staging = Backup_job::setting('STAGING_DIR');
$archive = $staging . '/backup.tar.gz';
$encrypted = $archive . '.enc';

try {
    foreach (['database/database.sql.gz', 'app/app.tar'] as $part) {
        if (!is_file("{$staging}/{$part}")) {
            Backup_job::stop("{$part} is missing; an earlier step did not finish");
        }
    }

    if (is_file("{$staging}/app/custom.failed")) {
        $why = str_replace("\n", ' / ', trim((string) file_get_contents("{$staging}/app/custom.failed")));
        Backup_job::say("runtime.php failed; the backup has everything else: {$why}");
    }

    Backup_job::run(['tar', '-czf', $archive, '-C', $staging, 'database', 'app']);
    Backup_file::encrypt($archive, $encrypted, Backup_job::setting('BACKUP_PUBLIC_KEY'));
    unlink($archive);

    $bytes = Object_storage::upload($encrypted, Backup_job::setting('UPLOAD_URL'));
    Backup_job::say("Uploaded the backup ({$bytes} bytes encrypted)");
} catch (Throwable $e) {
    Backup_job::empty_dir($staging);
    Backup_job::stop($e->getMessage());
}

Backup_job::empty_dir($staging);
Backup_job::say('Backup taken');
