<?php

/**
 * Takes the nightly backup of a running app: run by its daily backup Job
 * (Manifests::daily_backup_job, Daily_backup) in the app's namespace, as the
 * app's user, with its files volume at /files.
 *
 *  1. dumps the database (one consistent snapshot) to database.sql.gz
 *  2. packs /files into files.tar.gz
 *  3. encrypts both to the platform's public key (Backup_file)
 *  4. uploads the encrypted files to the signed links it was given
 *  5. removes its work folder
 *
 * Unlike backup.php (a deleted app's backup) it never drops or empties
 * anything. It is a separate script on purpose: a node still holding an
 * older app base image has no such file, so the Job fails instead of
 * running an older backup.php that would drop the database.
 *
 * The work folder is on the files volume itself (left out of the archive),
 * not the pod's small disk; the unencrypted copies are deleted as soon as
 * their encrypted copy is written.
 *
 * Environment: DB_HOST, DB_PORT, DB_NAME (also the user), DB_PASSWORD,
 * BACKUP_PUBLIC_KEY, DATABASE_UPLOAD_URL, FILES_UPLOAD_URL.
 */

const FILES_DIR = '/files';
const WORK_DIR_NAME = '.trongate-cloud-daily-backup';

// Copied in from modules/platform/live (docker/app-base/Dockerfile)
require '/usr/local/lib/trongate-cloud/Database_dump.php';
require '/usr/local/lib/trongate-cloud/Backup_file.php';
require '/usr/local/lib/trongate-cloud/Object_storage.php';

function say(string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
}

function stop(string $message): never {
    fwrite(STDERR, 'Backup failed: ' . $message . PHP_EOL);
    exit(1);
}

function setting(string $name): string {
    $value = getenv($name);

    if ($value === false || $value === '') {
        stop("{$name} is not set");
    }

    return $value;
}

/**
 * Runs a command, its output passed through; stops on an exit code not in $ok
 *
 * @param list<int> $ok
 */
function run(array $command, array $ok = [0]): void {
    $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes);

    if ($process === false) {
        stop('cannot start ' . $command[0]);
    }

    $exit_code = proc_close($process);

    if (!in_array($exit_code, $ok, true)) {
        stop(implode(' ', $command) . " exited with {$exit_code}");
    }
}

/** Encrypts $plain_path next to it as .enc and deletes the unencrypted file */
function encrypt(string $plain_path): string {
    $encrypted_path = $plain_path . '.enc';
    Backup_file::encrypt($plain_path, $encrypted_path, setting('BACKUP_PUBLIC_KEY'));
    unlink($plain_path);

    return $encrypted_path;
}

function remove_work_dir(string $work_dir): void {
    foreach (glob($work_dir . '/*') ?: [] as $path) {
        unlink($path);
    }

    rmdir($work_dir);
}

$work_dir = FILES_DIR . '/' . WORK_DIR_NAME;

// Left over from a Job that was stopped half way
if (is_dir($work_dir)) {
    remove_work_dir($work_dir);
}

if (!mkdir($work_dir, 0700)) {
    stop("cannot create {$work_dir}");
}

try {
    $database_path = $work_dir . '/database.sql.gz';
    $files_path = $work_dir . '/files.tar.gz';

    $pdo = Database_dump::connect(setting('DB_HOST'), (int) setting('DB_PORT'), setting('DB_NAME'), setting('DB_NAME'), setting('DB_PASSWORD'));
    Database_dump::write($pdo, setting('DB_NAME'), $database_path, 'say');
    $database_path = encrypt($database_path);

    // The app keeps running: GNU tar exits 1 when a file changed while it was
    // read, and that file is still in the archive as it was at that moment
    run(['tar', '-czf', $files_path, '--warning=no-file-changed', '--exclude=./' . WORK_DIR_NAME, '-C', FILES_DIR, '.'], [0, 1]);
    $files_path = encrypt($files_path);
    say('Packed and encrypted the files');

    say('Uploaded database (' . Object_storage::upload($database_path, setting('DATABASE_UPLOAD_URL')) . ' bytes)');
    say('Uploaded files (' . Object_storage::upload($files_path, setting('FILES_UPLOAD_URL')) . ' bytes)');
} catch (Throwable $e) {
    remove_work_dir($work_dir);
    stop($e->getMessage());
}

remove_work_dir($work_dir);
say('Backup taken');
