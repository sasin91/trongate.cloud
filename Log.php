<?php

require_once __DIR__ . '/Log_writer.php';
require_once __DIR__ . '/Stream_writer.php';
require_once __DIR__ . '/Error_log_writer.php';

/**
 * A log, after Monolog, for web requests and command-line scripts alike:
 * each message goes to every writer (Stream_writer, Error_log_writer, or
 * one of your own) as one entry; a blank message is skipped. A writer that
 * fails never stops the caller; the failure goes to STDERR.
 *
 *   $log = new Log([new Stream_writer('[backup] ')]);
 *   $log->info('Dumped 12 tables');
 *
 *   $log = new Log([new Error_log_writer('[webhooks] ')]);
 *   $log->warn('Unknown event: push');
 *
 * Framework-free.
 */
class Log {

    const LEVELS = ['info', 'warn', 'error'];

    /** @param list<Log_writer> $writers */
    function __construct(private array $writers = []) {
    }

    function add_writer(Log_writer $writer): void {
        $this->writers[] = $writer;
    }

    function info(string $message): void {
        $this->write('info', $message);
    }

    function warn(string $message): void {
        $this->write('warn', $message);
    }

    function error(string $message): void {
        $this->write('error', $message);
    }

    /** $message at $level; an unknown level is written as info */
    function write(string $level, string $message): void {
        if (!in_array($level, static::LEVELS, true)) {
            $level = 'info';
        }

        $message = rtrim($message);

        if ($message === '') {
            return;
        }

        foreach ($this->writers as $writer) {
            try {
                $writer->write($level, $message);
            } catch (Throwable $e) {
                fwrite(STDERR, get_class($writer) . ' failed: ' . $e->getMessage() . PHP_EOL);
            }
        }
    }
}
