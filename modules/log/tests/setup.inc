<?php
require_once __DIR__ . '/../Log.php';

/** Keeps every message it is given */
final class Kept_lines implements Log_writer {
    /** @var list<string> */
    public array $lines = [];

    function write(string $level, string $message): void {
        $this->lines[] = "{$level} {$message}";
    }
}
