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

/** A single upload (S3 PUT) can be at most this big */
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024 * 1024;

/** Rows per INSERT statement in the dump */
const ROWS_PER_INSERT = 100;

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
    $dsn = 'mysql:host=' . setting('DB_HOST') . ';port=' . setting('DB_PORT') . ';dbname=' . setting('DB_NAME') . ';charset=utf8mb4';

    return new PDO($dsn, setting('DB_NAME'), setting('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
    ]);
}

/**
 * The database's tables and views, by name
 *
 * @return array{tables: list<string>, views: list<string>}
 */
function list_tables(PDO $pdo): array {
    $tables = [];
    $views = [];

    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        if ($row[1] === 'VIEW') {
            $views[] = $row[0];
        } else {
            $tables[] = $row[0];
        }
    }

    return ['tables' => $tables, 'views' => $views];
}

function quote_name(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function sql_value(PDO $pdo, mixed $value): string {
    if ($value === null) {
        return 'NULL';
    }

    return $pdo->quote((string) $value);
}

/** One table's CREATE TABLE and INSERTs */
function dump_table(PDO $pdo, $out, string $table): int {
    $name = quote_name($table);
    $create = $pdo->query("SHOW CREATE TABLE {$name}")->fetch(PDO::FETCH_NUM)[1];

    gzwrite($out, "\n-- Table {$table}\nDROP TABLE IF EXISTS {$name};\n{$create};\n");

    $rows = $pdo->query("SELECT * FROM {$name}", PDO::FETCH_NUM);
    $batch = [];
    $count = 0;

    foreach ($rows as $row) {
        $values = [];

        foreach ($row as $value) {
            $values[] = sql_value($pdo, $value);
        }

        $batch[] = '(' . implode(', ', $values) . ')';
        $count++;

        if (count($batch) === ROWS_PER_INSERT) {
            gzwrite($out, "INSERT INTO {$name} VALUES\n" . implode(",\n", $batch) . ";\n");
            $batch = [];
        }
    }

    if ($batch !== []) {
        gzwrite($out, "INSERT INTO {$name} VALUES\n" . implode(",\n", $batch) . ";\n");
    }

    $rows->closeCursor();

    return $count;
}

function dump_view(PDO $pdo, $out, string $view): void {
    $name = quote_name($view);
    $create = $pdo->query("SHOW CREATE VIEW {$name}")->fetch(PDO::FETCH_NUM)[1];
    // Without its DEFINER the view belongs to whoever restores it, so it imports anywhere
    $create = preg_replace('/ DEFINER=`[^`]*`@`[^`]*`/', '', $create);

    gzwrite($out, "\n-- View {$view}\nDROP VIEW IF EXISTS {$name};\n{$create};\n");
}

function dump_database(PDO $pdo, string $path): void {
    $out = gzopen($path, 'wb6');

    if ($out === false) {
        stop("cannot write {$path}");
    }

    gzwrite($out, '-- Backup of database ' . setting('DB_NAME') . ' taken by trongate.cloud on ' . gmdate('Y-m-d H:i:s') . " UTC\n");
    gzwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n");

    $names = list_tables($pdo);

    foreach ($names['tables'] as $table) {
        $rows = dump_table($pdo, $out, $table);
        say("Dumped table {$table} ({$rows} rows)");
    }

    foreach ($names['views'] as $view) {
        dump_view($pdo, $out, $view);
        say("Dumped view {$view}");
    }

    gzwrite($out, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    gzclose($out);
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
    $bytes = filesize($path);

    if ($bytes > MAX_UPLOAD_BYTES) {
        stop(basename($path) . " is {$bytes} bytes; one upload can be at most 5 GB");
    }

    $file = fopen($path, 'rb');
    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $file,
        CURLOPT_INFILESIZE => $bytes,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 30,
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    fclose($file);

    if ($status < 200 || $status > 299) {
        stop('uploading ' . basename($path) . " failed (HTTP {$status}) {$error} " . substr((string) $response, 0, 500));
    }

    say('Uploaded ' . basename($path) . " ({$bytes} bytes)");
}

function warn(string $message): void {
    fwrite(STDERR, 'Warning: ' . $message . PHP_EOL);
}

function drop_database(PDO $pdo): void {
    try {
        $pdo->exec('DROP DATABASE ' . quote_name(setting('DB_NAME')));
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
dump_database($pdo, $database_path);
pack_files($files_path);

upload($database_path, setting('DATABASE_UPLOAD_URL'));
upload($files_path, setting('FILES_UPLOAD_URL'));

// Both are safe in the bucket: remove the originals. A failure from here on
// still counts as a backup taken (the copy in the bucket is whole), so it
// only warns: the platform removes the app's database user and volume claim
// after this either way.
drop_database($pdo);
empty_files();
