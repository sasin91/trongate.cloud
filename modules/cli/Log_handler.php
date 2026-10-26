<?php

/**
 * Where a Log sends its lines: a stream, a database table, ...
 */
interface Log_handler {

    /** One line, never empty, at one of the log's levels */
    function handle(string $level, string $line): void;
}
