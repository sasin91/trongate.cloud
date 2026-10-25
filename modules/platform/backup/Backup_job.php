<?php

/**
 * What the steps of an app's nightly backup Job share (dump_database.php,
 * pack_and_upload.php). They run from the worker image in the app's
 * namespace, so they print to the Job's log and exit non-zero to fail it.
 */
final class Backup_job {

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
        $process = proc_open(['find', $dir, '-mindepth', '1', '-delete'], [1 => STDOUT, 2 => STDERR], $pipes);

        if ($process === false || proc_close($process) !== 0) {
            self::stop("cannot empty {$dir}");
        }
    }

    /**
     * Runs a command, its output passed through; stops on an exit code not in $ok
     *
     * @param list<int> $ok
     */
    static function run(array $command, array $ok = [0]): void {
        $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes);

        if ($process === false) {
            self::stop('cannot start ' . $command[0]);
        }

        $exit_code = proc_close($process);

        if (!in_array($exit_code, $ok, true)) {
            self::stop(implode(' ', $command) . " exited with {$exit_code}");
        }
    }
}
