<?php

/**
 * Splits SQL into statements as it arrives, piece by piece, so a file of
 * any size is read without holding all of it: the same split as
 * App_database::statements() (a ";" inside a string, a `quoted name` or a
 * comment does not separate), for Sql_import.
 *
 * Each statement comes with the byte offset just after its ";" and the line
 * it starts on, so an import can stop after any statement and carry on later
 * from there with a new Sql_stream($position, $line).
 *
 * Framework-free.
 */
final class Sql_stream {

    /** Characters that may start a string, a quoted name, a comment or end a statement */
    const SPECIAL = "'\"`#-/;";

    /** What is not split yet: from the start of the current statement */
    private string $buffer = '';

    /** Absolute offset of $buffer's first byte */
    private int $base;

    /** Where scanning carries on in $buffer */
    private int $scan = 0;

    /** Lines are counted in $buffer up to this offset... */
    private int $counted = 0;

    /** ...which is on this line */
    private int $counted_line;

    /** null outside a string or quoted name, else its quote character */
    private ?string $quote = null;

    /** null outside a comment, else 'line' or 'block' */
    private ?string $comment = null;

    /**
     * @param int $position absolute offset the first piece starts at (just after a statement's ";")
     * @param int $line the line $position is on
     */
    function __construct(int $position = 0, int $line = 1) {
        $this->base = $position;
        $this->counted_line = $line;
    }

    /**
     * Add the next piece of SQL
     *
     * @return list<array{statement: string, end: int, line: int, end_line: int}> the statements it completes, trimmed, empty ones left out
     */
    function feed(string $piece): array {
        $this->buffer .= $piece;

        return $this->split(false);
    }

    /**
     * The SQL has ended: the statements still open, the last one without its ";"
     *
     * @return list<array{statement: string, end: int, line: int, end_line: int}>
     */
    function finish(): array {
        $statements = $this->split(true);
        $rest = $this->statement(0, strlen($this->buffer));

        if ($rest !== null) {
            $statements[] = $rest;
        }

        $this->drop_before(strlen($this->buffer));

        return $statements;
    }

    /**
     * @return list<array{statement: string, end: int, line: int, end_line: int}>
     */
    private function split(bool $final): array {
        $statements = [];
        $length = strlen($this->buffer);
        $start = 0;

        while ($this->scan < $length) {
            if ($this->quote !== null) {
                $waiting = !$this->skip_quoted($length, $final);
            } elseif ($this->comment !== null) {
                $waiting = !$this->skip_comment($length, $final);
            } else {
                $end = $this->next_end($length, $final);
                $waiting = $end === null;

                if ($end !== null && $end >= 0) {
                    $statement = $this->statement($start, $end);
                    $start = $end + 1;

                    if ($statement !== null) {
                        $statements[] = $statement;
                    }
                }
            }

            if ($waiting) {
                break;
            }
        }

        $this->drop_before($start);

        return $statements;
    }

    /**
     * Scan outside strings and comments to the next ";" and return its
     * offset; -1 after entering a string or comment; null when more SQL is
     * needed to tell what comes next
     */
    private function next_end(int $length, bool $final): ?int {
        $i = $this->scan + strcspn($this->buffer, self::SPECIAL, $this->scan);

        if ($i >= $length) {
            $this->scan = $length;

            return null;
        }

        $char = $this->buffer[$i];
        $next = $this->buffer[$i + 1] ?? null;

        if ($char === ';') {
            $this->scan = $i + 1;

            return $i;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $this->quote = $char;
            $this->scan = $i + 1;

            return -1;
        }

        if ($char === '#') {
            $this->comment = 'line';
            $this->scan = $i + 1;

            return -1;
        }

        // "--" starts a comment only before a space or the end, "/*" always
        if ($char === '-') {
            return $this->dash($i, $length, $final);
        }

        if ($next === null && !$final) {
            $this->scan = $i;

            return null;
        }

        if ($next === '*') {
            $this->comment = 'block';
            $this->scan = $i + 2;

            return -1;
        }

        $this->scan = $i + 1;

        return -1;
    }

    private function dash(int $i, int $length, bool $final): ?int {
        if ($i + 2 >= $length && !$final) {
            $this->scan = $i;

            return null;
        }

        $is_comment = substr($this->buffer, $i, 2) === '--' && ctype_space($this->buffer[$i + 2] ?? ' ');

        if ($is_comment) {
            $this->comment = 'line';
        }

        $this->scan = $i + 1;

        return -1;
    }

    /**
     * To the end of the string or quoted name; false when more SQL is needed.
     * A doubled quote, or a backslash in a string, escapes.
     */
    private function skip_quoted(int $length, bool $final): bool {
        $stops = $this->quote === '`' ? '`' : $this->quote . '\\';
        $i = $this->scan + strcspn($this->buffer, $stops, $this->scan);

        if ($i >= $length) {
            $this->scan = $length;

            return false;
        }

        if ($i + 1 >= $length && !$final) {
            $this->scan = $i;

            return false;
        }

        if ($this->buffer[$i] === '\\') {
            $this->scan = $i + 2;

            return true;
        }

        if (($this->buffer[$i + 1] ?? '') === $this->quote) {
            $this->scan = $i + 2;

            return true;
        }

        $this->quote = null;
        $this->scan = $i + 1;

        return true;
    }

    /**
     * To the end of the comment; false when more SQL is needed
     */
    private function skip_comment(int $length, bool $final): bool {
        $closer = $this->comment === 'line' ? "\n" : '*/';
        $end = strpos($this->buffer, $closer, $this->scan);

        if ($end === false) {
            // A "*" at the very end may be half of "*/"
            $this->scan = $this->comment === 'block' && !$final ? max($this->scan, $length - 1) : $length;

            return false;
        }

        $this->comment = null;
        $this->scan = $end + strlen($closer);

        return true;
    }

    /**
     * The statement from $start to $end in $buffer, trimmed, or null when it is only whitespace
     *
     * @return array{statement: string, end: int, line: int, end_line: int}|null
     */
    private function statement(int $start, int $end): ?array {
        $text = substr($this->buffer, $start, $end - $start);
        $trimmed = trim($text);
        $after = min($end + 1, strlen($this->buffer));

        if ($trimmed === '') {
            $this->line_at($after);

            return null;
        }

        $leading = strlen($text) - strlen(ltrim($text));

        return [
            'statement' => $trimmed,
            'end' => $this->base + $after,
            'line' => $this->line_at($start + $leading),
            'end_line' => $this->line_at($after),
        ];
    }

    /**
     * The line an offset in $buffer is on; offsets only move forward
     */
    private function line_at(int $offset): int {
        $this->counted_line += substr_count($this->buffer, "\n", $this->counted, $offset - $this->counted);
        $this->counted = $offset;

        return $this->counted_line;
    }

    /**
     * Forget what was split: the buffer keeps only the statement still open
     */
    private function drop_before(int $start): void {
        if ($start === 0) {
            return;
        }

        $this->line_at($start);
        $this->counted -= $start;
        $this->base += $start;
        $this->buffer = substr($this->buffer, $start);
        $this->scan -= $start;
    }
}
