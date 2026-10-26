<?php

require_once __DIR__ . '/Log_handler.php';
require_once __DIR__ . '/Stream_handler.php';
require_once __DIR__ . '/Error_log_handler.php';

/**
 * A log, after Monolog, for web requests and command-line scripts alike:
 * each message goes to every handler (Stream_handler, Error_log_handler, or
 * one of your own), one line at a time, so a multi-line message reads like
 * streamed output. A handler that fails never stops the caller; the failure
 * goes to STDERR.
 *
 *   $log = new Log([new Stream_handler('[backup] ')]);
 *   $log->info('Dumped 12 tables');
 *
 *   $log = new Log([new Error_log_handler('[webhooks] ')]);
 *   $log->warn('Unknown event: push');
 *
 * Framework-free.
 */
class Log {

    const LEVELS = ['info', 'warn', 'error'];

    /** @param list<Log_handler> $handlers */
    function __construct(private array $handlers = []) {
    }

    function push_handler(Log_handler $handler): void {
        $this->handlers[] = $handler;
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

        foreach (preg_split('/\r\n|\r|\n/', rtrim($message, "\r\n")) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            foreach ($this->handlers as $handler) {
                try {
                    $handler->handle($level, $line);
                } catch (Throwable $e) {
                    fwrite(STDERR, get_class($handler) . ' failed: ' . $e->getMessage() . PHP_EOL);
                }
            }
        }
    }
}
