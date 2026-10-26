<?php

require_once __DIR__ . '/Log_handler.php';

/**
 * Writes each line to a stream (STDOUT by default), such as a container's log:
 * "{prefix}{level}: {line}", or "{prefix}{line}" without level names.
 */
final class Stream_handler implements Log_handler {

    /** @var resource */
    private $stream;

    /** @param resource|null $stream */
    function __construct(private string $prefix = '', private bool $level_names = true, $stream = null) {
        $this->stream = $stream ?? STDOUT;
    }

    function handle(string $level, string $line): void {
        $label = $this->level_names ? "{$level}: " : '';
        fwrite($this->stream, $this->prefix . $label . $line . PHP_EOL);
    }
}
