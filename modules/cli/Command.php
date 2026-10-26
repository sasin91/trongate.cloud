<?php

/**
 * Runs another program from a command-line script.
 *
 * Framework-free.
 */
final class Command {

    /**
     * Runs $command (program and arguments, no shell), its output passed
     * through to this script's
     *
     * @param list<string> $command
     * @throws RuntimeException when it cannot start or exits non-zero
     */
    static function run(array $command): void {
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
