<?php

require_once __DIR__ . '/Sql_rules.php';
require_once __DIR__ . '/Setup_sql_plan.php';
require_once __DIR__ . '/App_database.php';

/**
 * The SQL a new app's database starts with (Setup_sql_plan): changes
 * allowed, up to Setup_sql_plan::MAX_BYTES, the transaction and lock
 * statements a dump wraps itself in left out, and SET only for the session
 * settings dumps use (Setup_sql_plan::statement_refusal).
 */
final class Setup_sql_rules extends Sql_rules {

    protected bool $always_number = true;

    function max_bytes(): int {
        return Setup_sql_plan::MAX_BYTES;
    }

    function too_long_refusal(): string {
        return 'That is more than ' . Setup_sql_plan::max_megabytes() . ' MB of SQL. Import it as a file from the Database tab once the app runs.';
    }

    function skips(string $statement): bool {
        return (bool) preg_match(Setup_sql_plan::SKIPPED, App_database::without_comments($statement));
    }

    function statement_refusal(string $statement): ?string {
        return Setup_sql_plan::statement_refusal($statement, App_database::without_comments($statement));
    }
}
