<?php

/**
 * Runs another program, after Symfony's Process: the command is a list
 * (program and arguments, no shell unless you run `sh -c` yourself), with an
 * optional working folder, environment and timeout.
 *
 *   $process = new Process(['tar', '-czf', 'backup.tar.gz', 'database']);
 *   $process->must_run();
 *
 *   $process = new Process(['diff3', '--merge', ...]);
 *   $process->run();
 *   $merged = $process->output();
 *
 *   (new Process(['sh', '-c', $script], timeout: 600))
 *       ->run(fn(string $type, string $line) => $log->write($type === Process::ERR ? 'warn' : 'info', $line));
 *
 * With a callback, each line is handed to it as it arrives (Process::OUT or
 * Process::ERR) instead of being kept, so a long build's output is never held
 * in memory. Output with no line breaks (a PDF, video) is handed over in
 * pieces of at most MAX_LINE_BYTES, so memory stays bounded there too.
 *
 * Without a callback, output() and error_output() hold it all in memory, byte
 * for byte: fine for a command's answer, not for a big file. Have the program
 * write big output to a file itself (`tar -f`, `pg_dump -f`, `sh -c 'cmd > file'`),
 * as the backup runtime does, or pass a callback.
 *
 * On timeout the process gets SIGTERM, then SIGKILL KILL_AFTER_SECONDS later.
 * Like Symfony, that reaches the process itself, not what it started; wrap
 * the command in GNU `timeout` to stop a whole process tree.
 *
 * Framework-free.
 */
final class Process {

    const OUT = 'out';
    const ERR = 'err';

    /** Seconds between SIGTERM and SIGKILL on timeout */
    const KILL_AFTER_SECONDS = 10;

    /** Bytes read from a pipe at a time: PHP's own stream chunk size */
    const READ_BYTES = 8192;

    /** Longest line kept before it is handed to the callback anyway (1 MB) */
    const MAX_LINE_BYTES = 1048576;

    /** Seconds to wait for output before checking the timeout again */
    const POLL_SECONDS = 1;

    /** Signal numbers, so proc_terminate does not need the pcntl extension */
    const SIGTERM = 15;
    const SIGKILL = 9;

    private string $output = '';
    private string $error_output = '';
    private ?int $exit_code = null;
    private bool $timed_out = false;

    /**
     * @param list<string> $command
     * @param array<string, string>|null $env null: this script's own environment
     * @param float|null $timeout seconds, or null for none
     */
    function __construct(
        private array $command,
        private ?string $cwd = null,
        private ?array $env = null,
        private ?float $timeout = 60,
    ) {
    }

    /**
     * Runs the process to its end
     *
     * @param (callable(string $type, string $line): void)|null $callback
     * @return int its exit code
     * @throws RuntimeException when it cannot be started
     */
    function run(?callable $callback = null): int {
        $process = @proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->cwd, $this->env);

        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start ' . $this->command[0]);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $open = [self::OUT => $pipes[1], self::ERR => $pipes[2]];
        $buffers = [self::OUT => '', self::ERR => ''];
        $started = microtime(true);
        $terminated_at = null;

        // Both pipes are open at the start, so the loop runs at least once
        do {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, self::POLL_SECONDS) === false) {
                break;
            }

            foreach ($open as $type => $pipe) {
                $chunk = fread($pipe, self::READ_BYTES);

                if ($chunk !== false && $chunk !== '' && $callback === null) {
                    $this->keep($type, $chunk);
                } elseif ($chunk !== false && $chunk !== '') {
                    $buffers[$type] .= $chunk;

                    // Complete lines go out now; a partial tail waits for the rest
                    while (($pos = strpos($buffers[$type], "\n")) !== false) {
                        $callback($type, substr($buffers[$type], 0, $pos));
                        $buffers[$type] = substr($buffers[$type], $pos + 1);
                    }

                    // Output without line breaks must not grow without end
                    if (strlen($buffers[$type]) >= self::MAX_LINE_BYTES) {
                        $callback($type, $buffers[$type]);
                        $buffers[$type] = '';
                    }
                }

                if (feof($pipe)) {
                    if ($buffers[$type] !== '') {
                        $callback($type, $buffers[$type]);
                    }

                    fclose($pipe);
                    unset($open[$type]);
                }
            }

            if ($this->timeout !== null && microtime(true) - $started > $this->timeout) {
                $this->timed_out = true;
                $terminated_at ??= microtime(true);
                proc_terminate($process, microtime(true) - $terminated_at > self::KILL_AFTER_SECONDS ? self::SIGKILL : self::SIGTERM);
            }
        } while ($open !== []);

        foreach ($open as $pipe) {
            fclose($pipe);
        }

        return $this->exit_code = proc_close($process);
    }

    /**
     * Runs the process and insists it succeeds
     *
     * @param (callable(string $type, string $line): void)|null $callback
     * @throws RuntimeException when it cannot start, times out or exits non-zero
     */
    function must_run(?callable $callback = null): self {
        $exit_code = $this->run($callback);

        if ($this->timed_out) {
            throw new RuntimeException($this->command_line() . " timed out after {$this->timeout} seconds");
        }

        if ($exit_code !== 0) {
            $error = trim($this->error_output);
            throw new RuntimeException($this->command_line() . " exited with {$exit_code}" . ($error !== '' ? ": {$error}" : ''));
        }

        return $this;
    }

    function output(): string {
        return $this->output;
    }

    function error_output(): string {
        return $this->error_output;
    }

    /** null until it has run */
    function exit_code(): ?int {
        return $this->exit_code;
    }

    function is_successful(): bool {
        return $this->exit_code === 0;
    }

    function timed_out(): bool {
        return $this->timed_out;
    }

    /** The command as you would type it in a shell, for messages */
    function command_line(): string {
        return implode(' ', array_map(self::shell_word(...), $this->command));
    }

    /** Plain words (paths, flags, key=value) stay bare; anything else is quoted */
    private static function shell_word(string $part): string {
        $is_plain = preg_match('/^[\w.\/:=-]+$/', $part) === 1;

        return $is_plain ? $part : escapeshellarg($part);
    }

    /** Output kept as it came, byte for byte */
    private function keep(string $type, string $chunk): void {
        if ($type === self::OUT) {
            $this->output .= $chunk;
        } else {
            $this->error_output .= $chunk;
        }
    }
}
