<?php

/**
 * Where a Log sends its lines: a stream, a database table, ...
 */
interface Log_writer {

    /** One message, never empty, at one of the log's levels */
    function write(string $level, string $message): void;
}
