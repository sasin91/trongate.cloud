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
 * Each action prints to the Job's log; a failing one exits non-zero, which
 * fails the Job.
 *
 * Environment: STAGING_DIR; for dump DB_HOST, DB_PORT, DB_NAME (also the
 * user) and DB_PASSWORD; for upload BACKUP_PUBLIC_KEY and UPLOAD_URL.
 */

require __DIR__ . '/../live/Database_dump.php';
require __DIR__ . '/../live/Backup_file.php';
require __DIR__ . '/../live/Object_storage.php';

final class Backup_runtime {

    /** Each part of the Job, and its actions in order */
    const PIPELINES = [
        'dump' => ['make_staging', 'dump_database'],
        'upload' => ['check_parts', 'note_module_failure', 'pack', 'encrypt', 'upload', 'clean_up'],
    ];

    static function run(string $pipeline): void {
        if (!isset(self::PIPELINES[$pipeline])) {
            self::stop('Usage: php runtime.php ' . implode('|', array_keys(self::PIPELINES)));
        }

        foreach (self::PIPELINES[$pipeline] as $action) {
            try {
                self::$action(self::setting('STAGING_DIR'));
            } catch (Throwable $e) {
                // An upload that fails still leaves no copy of the data behind
                if ($pipeline === 'upload') {
                    try {
                        self::clean_up(self::setting('STAGING_DIR'));
                    } catch (Throwable) {
                    }
                }

                self::stop($e->getMessage());
            }
        }

        self::say("Done: {$pipeline}");
    }

    /** The staging folders, emptied of what an earlier, stopped run left */
    static function make_staging(string $staging): void {
        if (is_dir($staging)) {
            self::empty_dir($staging);
        }

        // The app step mounts app/ as its /backup: made here, so it is the app user's
        foreach ([$staging, "{$staging}/database", "{$staging}/app"] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700)) {
                throw new RuntimeException("cannot create {$dir}");
            }
        }
    }

    static function dump_database(string $staging): void {
        $name = self::setting('DB_NAME');
        $pdo = Database_dump::connect(self::setting('DB_HOST'), (int) self::setting('DB_PORT'), $name, $name, self::setting('DB_PASSWORD'));
        Database_dump::write($pdo, $name, "{$staging}/database/database.sql.gz", [self::class, 'say']);
    }

    static function check_parts(string $staging): void {
        foreach (['database/database.sql.gz', 'app/app.tar'] as $part) {
            if (!is_file("{$staging}/{$part}")) {
                throw new RuntimeException("{$part} is missing; an earlier step did not finish");
            }
        }
    }

    /** The worker reads this line from the log (Daily_backup_run::module_failure) */
    static function note_module_failure(string $staging): void {
        if (is_file("{$staging}/app/custom.failed")) {
            $why = str_replace("\n", ' / ', trim((string) file_get_contents("{$staging}/app/custom.failed")));
            self::say("runtime.php failed; the backup has everything else: {$why}");
        }
    }

    static function pack(string $staging): void {
        self::command(['tar', '-czf', "{$staging}/backup.tar.gz", '-C', $staging, 'database', 'app']);
    }

    static function encrypt(string $staging): void {
        Backup_file::encrypt("{$staging}/backup.tar.gz", "{$staging}/backup.tar.gz.enc", self::setting('BACKUP_PUBLIC_KEY'));
        unlink("{$staging}/backup.tar.gz");
    }

    static function upload(string $staging): void {
        $bytes = Object_storage::upload("{$staging}/backup.tar.gz.enc", self::setting('UPLOAD_URL'));
        self::say("Uploaded the backup ({$bytes} bytes encrypted)");
    }

    static function clean_up(string $staging): void {
        self::empty_dir($staging);
    }

    static function say(string $message): void {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    static function stop(string $message): never {
        fwrite(STDERR, 'Backup failed: ' . $message . PHP_EOL);
        exit(1);
    }

    static function setting(string $name): string {
        $value = getenv($name);

        if ($value === false || $value === '') {
            self::stop("{$name} is not set");
        }

        return $value;
    }

    /** Removes everything inside $dir, keeping $dir */
    static function empty_dir(string $dir): void {
        self::command(['find', $dir, '-mindepth', '1', '-delete']);
    }

    /** Runs a command, its output passed through */
    static function command(array $command): void {
        $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes);

        if ($process === false) {
            throw new RuntimeException('cannot start ' . $command[0]);
        }

        $exit_code = proc_close($process);

        if ($exit_code !== 0) {
            throw new RuntimeException(implode(' ', $command) . " exited with {$exit_code}");
        }
    }
}

Backup_runtime::run($argv[1] ?? '');
