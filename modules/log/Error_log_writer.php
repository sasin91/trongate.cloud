<?php

require_once __DIR__ . '/Log_writer.php';

/**
 * Writes each line with PHP's error_log(), so it lands wherever the server
 * keeps its errors (a web request's log, or STDERR on the command line):
 * "{prefix}{level}: {line}".
 */
final class Error_log_writer implements Log_writer {

    function __construct(private string $prefix = '') {
    }

    function write(string $level, string $message): void {
        error_log("{$this->prefix}{$level}: {$message}");
    }
}
