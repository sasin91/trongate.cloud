<?php

require_once __DIR__ . '/Log_writer.php';

/**
 * Writes each line to a stream (STDOUT by default), such as a container's log:
 * "{prefix}{level}: {line}", or "{prefix}{line}" without level names.
 */
final class Stream_writer implements Log_writer {

    /** @var resource */
    private $stream;

    /** @param resource|null $stream */
    function __construct(private string $prefix = '', private bool $level_names = true, $stream = null) {
        $this->stream = $stream ?? STDOUT;
    }

    function write(string $level, string $message): void {
        $label = $this->level_names ? "{$level}: " : '';
        fwrite($this->stream, $this->prefix . $label . $message . PHP_EOL);
    }
}
