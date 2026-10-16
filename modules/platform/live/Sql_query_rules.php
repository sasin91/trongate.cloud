<?php

require_once __DIR__ . '/Sql_rules.php';
require_once __DIR__ . '/App_database.php';

/**
 * The Database tab's SQL box and the MCP tools: SELECT, SHOW, DESCRIBE and
 * EXPLAIN, and with changes allowed INSERT, UPDATE, DELETE, REPLACE,
 * CREATE, ALTER, DROP, TRUNCATE and RENAME, up to MAX_STATEMENT_BYTES.
 * Anything else is refused: SET (which could lift the read-only mode and
 * the time limit), BEGIN, COMMIT, LOCK, HANDLER, LOAD DATA, CALL, PREPARE
 * and the like. App_database::run() runs what they allow.
 */
final class Sql_query_rules extends Sql_rules {

    function __construct(private bool $allow_changes) {
    }

    function max_bytes(): int {
        return App_database::MAX_STATEMENT_BYTES;
    }

    function too_long_refusal(): string {
        return 'That SQL is longer than ' . (App_database::MAX_STATEMENT_BYTES / 1024) . ' KB.';
    }

    /**
     * Executable comments and files on the server anywhere in it, also
     * outside a statement
     */
    function sql_refusal(string $sql): ?string {
        return App_database::content_refusal($sql);
    }

    function empty_refusal(): string {
        return 'There is no statement to run.';
    }

    function statement_refusal(string $statement): ?string {
        return App_database::content_refusal($statement) ?? App_database::statement_refusal($statement, $this->allow_changes);
    }
}
