<?php

require_once __DIR__ . '/App_database.php';

/**
 * What SQL may run through one of the panel's ways in, and why not. Each
 * way in has one rule set, used both by its real submit and by the live
 * check while the SQL is written (Sql_checker), so the two always agree:
 * Sql_query_rules for the Database tab's SQL box and the MCP tools,
 * Setup_sql_rules for the SQL a new app's database starts with.
 *
 * A rule set says how much SQL it takes, which statements it leaves out,
 * and why one statement may not run; plan() and refusal() walk the
 * statements the same way for all of them.
 *
 * Framework-free: the panel and the worker both use it.
 */
abstract class Sql_rules {

    /** Number a refused statement even when it is the only one ("Statement 1: ...") */
    protected bool $always_number = false;

    /** The most bytes of SQL these rules take at once */
    abstract function max_bytes(): int;

    /** Why SQL over max_bytes() is refused */
    abstract function too_long_refusal(): string;

    /**
     * Why one statement may not run, or null when it may
     */
    abstract function statement_refusal(string $statement): ?string;

    /**
     * Whether a statement is left out rather than run (a dump's own
     * START TRANSACTION and the like). None by default.
     */
    function skips(string $statement): bool {
        return false;
    }

    /**
     * Why the SQL as a whole may not run, before its statements are looked
     * at, or null. Nothing by default.
     */
    function sql_refusal(string $sql): ?string {
        return null;
    }

    /**
     * Why SQL without a statement to run is refused, or null when that is fine
     */
    function empty_refusal(): ?string {
        return null;
    }

    /**
     * The statements to run, how many were left out, or why the SQL may not
     * run: the first refusal, as the real submit reports it
     *
     * @return array{statements: list<string>, skipped: int, refusal: string|null}
     */
    function plan(string $sql): array {
        $plan = ['statements' => [], 'skipped' => 0, 'refusal' => null];

        if (strlen($sql) > $this->max_bytes()) {
            $plan['refusal'] = $this->too_long_refusal();

            return $plan;
        }

        $plan['refusal'] = $this->sql_refusal($sql);

        if ($plan['refusal'] !== null) {
            return $plan;
        }

        $statements = App_database::statements($sql);

        foreach ($statements as $i => $statement) {
            if ($this->skips($statement)) {
                $plan['skipped']++;
                continue;
            }

            $refusal = $this->statement_refusal($statement);

            if ($refusal !== null) {
                $plan['refusal'] = $this->numbered($refusal, $i + 1, count($statements));
                $plan['statements'] = [];

                return $plan;
            }

            $plan['statements'][] = $statement;
        }

        if ($plan['statements'] === []) {
            $plan['refusal'] = $this->empty_refusal();
        }

        return $plan;
    }

    /**
     * Why the SQL may not run, or null when it may (plan()'s refusal)
     */
    function refusal(string $sql): ?string {
        return $this->plan($sql)['refusal'];
    }

    /**
     * A statement's refusal with its number, when there are several or the
     * rules always number it
     */
    function numbered(string $refusal, int $number, int $count): string {
        if ($count === 1 && !$this->always_number) {
            return $refusal;
        }

        return 'Statement ' . $number . ': ' . $refusal;
    }
}
