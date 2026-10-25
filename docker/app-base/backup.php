<?php

/**
 * Backs up a deleted app, then removes its data: run once by the backup Job
 * (Manifests::backup_job, App_backup) in the app's namespace, as the app's
 * user, with its files volume at /files.
 *
 *  1. dumps the database to database.sql.gz (tables with their rows, then views)
 *  2. packs /files into files.tar.gz
 *  3. uploads both to the signed links it was given
 *  4. drops the database and empties /files
 *
 * The dump and the archive are written to a folder on the files volume
 * itself (left out of the archive), not the pod's small disk. Any failure
 * before step 4 exits non-zero with the data untouched; a failure in step 4
 * only warns, since the backup is whole by then.
 *
 * Environment: DB_HOST, DB_PORT, DB_NAME (also the user), DB_PASSWORD,
 * DATABASE_UPLOAD_URL, FILES_UPLOAD_URL.
 */

const FILES_DIR = '/files';
const WORK_DIR_NAME = '.trongate-cloud-backup';

// Copied in from modules/platform/live (docker/app-base/Dockerfile)
require '/usr/local/lib/trongate-cloud/Database_dump.php';
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

function connect(): PDO {
    return Database_dump::connect(setting('DB_HOST'), (int) setting('DB_PORT'), setting('DB_NAME'), setting('DB_NAME'), setting('DB_PASSWORD'));
}

/** Runs a command, its output passed through; stops on a non-zero exit */
function run(array $command): void {
    $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes);

    if ($process === false) {
        stop('cannot start ' . $command[0]);
    }

    $exit_code = proc_close($process);

    if ($exit_code !== 0) {
        stop(implode(' ', $command) . " exited with {$exit_code}");
    }
}

function pack_files(string $path): void {
    run(['tar', '-czf', $path, '--exclude=./' . WORK_DIR_NAME, '-C', FILES_DIR, '.']);
}

function upload(string $path, string $url): void {
    try {
        $bytes = Object_storage::upload($path, $url);
    } catch (RuntimeException $e) {
        stop($e->getMessage());
    }

    say('Uploaded ' . basename($path) . " ({$bytes} bytes)");
}

function warn(string $message): void {
    fwrite(STDERR, 'Warning: ' . $message . PHP_EOL);
}

function drop_database(PDO $pdo): void {
    try {
        $pdo->exec('DROP DATABASE ' . Database_dump::quote_name(setting('DB_NAME')));
        say('Dropped database ' . setting('DB_NAME'));
    } catch (PDOException $e) {
        warn('the backup is taken, but dropping the database failed: ' . $e->getMessage());
    }
}

function empty_files(): void {
    $process = proc_open(['find', FILES_DIR, '-mindepth', '1', '-delete'], [1 => STDOUT, 2 => STDERR], $pipes);
    $exit_code = $process === false ? -1 : proc_close($process);

    if ($exit_code !== 0) {
        warn("the backup is taken, but emptying the files volume failed (find exited with {$exit_code})");
        return;
    }

    say('Emptied the files volume');
}

$work_dir = FILES_DIR . '/' . WORK_DIR_NAME;

if (!is_dir($work_dir) && !mkdir($work_dir, 0700)) {
    stop("cannot create {$work_dir}");
}

$database_path = $work_dir . '/database.sql.gz';
$files_path = $work_dir . '/files.tar.gz';

$pdo = connect();

try {
    Database_dump::write($pdo, setting('DB_NAME'), $database_path, 'say');
} catch (Throwable $e) {
    stop($e->getMessage());
}

pack_files($files_path);

upload($database_path, setting('DATABASE_UPLOAD_URL'));
upload($files_path, setting('FILES_UPLOAD_URL'));

// Both are safe in the bucket: remove the originals. A failure from here on
// still counts as a backup taken (the copy in the bucket is whole), so it
// only warns: the platform removes the app's database user and volume claim
// after this either way.
drop_database($pdo);
empty_files();
