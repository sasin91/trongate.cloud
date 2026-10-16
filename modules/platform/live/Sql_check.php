<?php

require_once __DIR__ . '/App_database.php';
require_once __DIR__ . '/Setup_sql_plan.php';

/**
 * SQL checked while it is typed or pasted, before it is submitted: the
 * Database tab's SQL box and the new-app wizard's typed SQL. Like Laravel
 * Precognition, it runs the same checks the real submit runs and nothing
 * else, and it never sends the SQL to a database. So it finds statements
 * that would be refused (and why, per statement), quotes, comments and
 * brackets that are never closed, but not misspelt keywords, missing
 * tables or columns, or anything else only MariaDB knows.
 *
 * Framework-free, like App_database and Setup_sql_plan.
 */
final class Sql_check {

    /** Statements listed with their problem; past that, only how many more there are */
    const MAX_PROBLEMS = 20;

    /** How much of a statement is shown next to its problem */
    const PREVIEW_CHARS = 80;

    /**
     * SQL for the Database tab's SQL box, checked as Database::query() checks it
     * (App_database::refusal)
     *
     * @return array{statements: int, skipped: int, problems: list<array{number: int, line: int, preview: string, message: string}>, more_problems: int, refusal: string|null}
     */
    static function database_tab(string $sql, bool $allow_changes): array {
        $check = self::empty_check();
        $check['refusal'] = $sql === '' ? null : App_database::refusal($sql, $allow_changes);

        if (strlen($sql) > App_database::MAX_STATEMENT_BYTES) {
            return $check;
        }

        $statements = App_database::statements($sql);
        $lines = self::start_lines($sql, $statements);
        $check['statements'] = count($statements);

        foreach ($statements as $i => $statement) {
            $message = App_database::content_refusal($statement) ?? App_database::statement_refusal($statement, $allow_changes);

            if ($message !== null) {
                $check = self::add_problem($check, $i + 1, $lines[$i], $statement, $message);
            }
        }

        return $check;
    }

    /**
     * Typed SQL for the new-app wizard's Database question, checked as
     * _validate_setup_sql checks it (Setup_sql_plan::plan)
     *
     * @return array{statements: int, skipped: int, problems: list<array{number: int, line: int, preview: string, message: string}>, more_problems: int, refusal: string|null}
     */
    static function setup(string $sql): array {
        $check = self::empty_check();
        $check['refusal'] = Setup_sql_plan::plan($sql)['refusal'];

        if (strlen($sql) > Setup_sql_plan::MAX_BYTES) {
            return $check;
        }

        $statements = App_database::statements($sql);
        $lines = self::start_lines($sql, $statements);

        foreach ($statements as $i => $statement) {
            $start = App_database::without_comments($statement);

            if (preg_match(Setup_sql_plan::SKIPPED, $start)) {
                $check['skipped']++;
                continue;
            }

            $check['statements']++;
            $message = Setup_sql_plan::statement_refusal($statement, $start);

            if ($message !== null) {
                $check = self::add_problem($check, $i + 1, $lines[$i], $statement, $message);
            }
        }

        return $check;
    }

    /**
     * One line saying how it went, for above the problems
     *
     * @param array{statements: int, skipped: int, problems: list<array<string, mixed>>, more_problems: int, refusal: string|null} $check
     */
    static function summary(array $check): string {
        $statements = $check['statements'] === 1 ? '1 statement' : $check['statements'] . ' statements';
        $skipped = $check['skipped'] === 0 ? '' : ', ' . $check['skipped'] . ' transaction or lock ' . ($check['skipped'] === 1 ? 'statement' : 'statements') . ' skipped';
        $problems = count($check['problems']) + $check['more_problems'];

        if ($check['refusal'] === null) {
            return $statements . $skipped . ', nothing refused. MariaDB still checks names and spelling when it runs.';
        }

        if ($problems === 0) {
            return $check['refusal'];
        }

        return $statements . $skipped . ', ' . ($problems === 1 ? '1 would be refused' : $problems . ' would be refused') . '.';
    }

    /**
     * @return array{statements: int, skipped: int, problems: list<array{number: int, line: int, preview: string, message: string}>, more_problems: int, refusal: string|null}
     */
    static function empty_check(): array {
        return ['statements' => 0, 'skipped' => 0, 'problems' => [], 'more_problems' => 0, 'refusal' => null];
    }

    /**
     * @param array{statements: int, skipped: int, problems: list<array{number: int, line: int, preview: string, message: string}>, more_problems: int, refusal: string|null} $check
     * @return array{statements: int, skipped: int, problems: list<array{number: int, line: int, preview: string, message: string}>, more_problems: int, refusal: string|null}
     */
    static function add_problem(array $check, int $number, int $line, string $statement, string $message): array {
        if (count($check['problems']) >= self::MAX_PROBLEMS) {
            $check['more_problems']++;

            return $check;
        }

        $check['problems'][] = [
            'number' => $number,
            'line' => $line,
            'preview' => self::preview($statement),
            'message' => $message,
        ];

        return $check;
    }

    /**
     * The line (from 1) each statement starts on in the SQL. statements()
     * gives them in order and as typed, so each is found after the one before.
     *
     * @param list<string> $statements
     * @return list<int>
     */
    static function start_lines(string $sql, array $statements): array {
        $lines = [];
        $offset = 0;

        foreach ($statements as $statement) {
            $found = strpos($sql, $statement, $offset);

            if ($found === false) {
                $found = $offset;
            }

            $lines[] = substr_count($sql, "\n", 0, $found) + 1;
            $offset = $found + strlen($statement);
        }

        return $lines;
    }

    /**
     * The start of a statement on one line, without its leading comments
     */
    static function preview(string $statement): string {
        $text = trim((string) preg_replace('/\s+/', ' ', App_database::without_comments($statement)));

        if (mb_strlen($text) <= self::PREVIEW_CHARS) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::PREVIEW_CHARS - 1)) . '…';
    }
}
