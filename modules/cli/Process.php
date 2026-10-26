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

    /** @var array<string, string> everything kept, per stream, when there is no callback */
    private array $kept = [self::OUT => '', self::ERR => ''];

    /** @var array<string, string> the partial line, per stream, waiting for its line break */
    private array $partial_lines = [self::OUT => '', self::ERR => ''];

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
     * @throws LogicException when this Process has already run
     */
    function run(?callable $callback = null): int {
        // One run per instance, so no output or state carries over: make a new Process to run again
        if ($this->exit_code !== null) {
            throw new LogicException($this->command_line() . ' has already run; make a new Process');
        }

        $process = @proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->cwd, $this->env);

        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start ' . $this->command[0]);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $open = [self::OUT => $pipes[1], self::ERR => $pipes[2]];
        $started = microtime(true);

        // Both pipes are open at the start, so the loop runs at least once
        do {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, self::POLL_SECONDS) === false) {
                break;
            }

            foreach ($open as $type => $pipe) {
                $this->take($type, (string) fread($pipe, self::READ_BYTES), $callback);

                if (feof($pipe)) {
                    $this->flush($type, $callback);
                    fclose($pipe);
                    unset($open[$type]);
                }
            }

            $this->stop_if_overdue($process, $started);
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
            $error = trim($this->kept[self::ERR]);
            throw new RuntimeException($this->command_line() . " exited with {$exit_code}" . ($error !== '' ? ": {$error}" : ''));
        }

        return $this;
    }

    function output(): string {
        return $this->kept[self::OUT];
    }

    function error_output(): string {
        return $this->kept[self::ERR];
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

    /** Keeps a chunk as it came, or hands its complete lines to the callback */
    private function take(string $type, string $chunk, ?callable $callback): void {
        if ($chunk === '') {
            return;
        }

        if ($callback === null) {
            $this->kept[$type] .= $chunk;
            return;
        }

        $lines = explode("\n", $this->partial_lines[$type] . $chunk);
        $this->partial_lines[$type] = array_pop($lines);

        foreach ($lines as $line) {
            $callback($type, $line);
        }

        // Output without line breaks must not grow without end
        if (strlen($this->partial_lines[$type]) >= self::MAX_LINE_BYTES) {
            $this->flush($type, $callback);
        }
    }

    /** Hands the partial line that is left to the callback */
    private function flush(string $type, ?callable $callback): void {
        if ($callback !== null && $this->partial_lines[$type] !== '') {
            $callback($type, $this->partial_lines[$type]);
        }

        $this->partial_lines[$type] = '';
    }

    /** Past the timeout: SIGTERM, then SIGKILL once KILL_AFTER_SECONDS more have passed */
    private function stop_if_overdue(mixed $process, float $started): void {
        $overdue_for = $this->timeout === null ? 0 : microtime(true) - $started - $this->timeout;

        if ($overdue_for <= 0) {
            return;
        }

        $this->timed_out = true;
        proc_terminate($process, $overdue_for > self::KILL_AFTER_SECONDS ? self::SIGKILL : self::SIGTERM);
    }
}
