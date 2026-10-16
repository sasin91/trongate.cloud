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
 * Each problem says where it is, line and column, the way a compiler
 * does, and whether commenting the statement out fixes it: the lines to
 * comment out for all of those are in fix_lines.
 *
 * Framework-free, like App_database and Setup_sql_plan.
 */
final class Sql_check {

    /** Problems shown in full; past that, only how many more there are */
    const MAX_PROBLEMS = 20;

    /**
     * SQL for the Database tab's SQL box, checked as Database::query() checks it
     * (App_database::refusal)
     *
     * @return array{statements: int, skipped: int, problems: list<array<string, mixed>>, more_problems: int, fix_lines: list<array{int, int}>, refusal: string|null}
     */
    static function database_tab(string $sql, bool $allow_changes): array {
        $check = self::empty_check();
        $check['refusal'] = trim($sql) === '' ? null : App_database::refusal($sql, $allow_changes);

        if (strlen($sql) > App_database::MAX_STATEMENT_BYTES) {
            return $check;
        }

        $statements = self::located_statements($sql);
        $check['statements'] = count($statements);

        foreach ($statements as $i => $statement) {
            $message = App_database::content_refusal($statement['text']) ?? App_database::statement_refusal($statement['text'], $allow_changes);

            if ($message !== null) {
                $check = self::add_problem($check, $sql, $statements, $i, $message);
            }
        }

        return $check;
    }

    /**
     * Typed SQL for the new-app wizard's Database question, checked as
     * _validate_setup_sql checks it (Setup_sql_plan::plan)
     *
     * @return array{statements: int, skipped: int, problems: list<array<string, mixed>>, more_problems: int, fix_lines: list<array{int, int}>, refusal: string|null}
     */
    static function setup(string $sql): array {
        $check = self::empty_check();
        $check['refusal'] = Setup_sql_plan::plan($sql)['refusal'];

        if (strlen($sql) > Setup_sql_plan::MAX_BYTES) {
            return $check;
        }

        $statements = self::located_statements($sql);

        foreach ($statements as $i => $statement) {
            $start = App_database::without_comments($statement['text']);

            if (preg_match(Setup_sql_plan::SKIPPED, $start)) {
                $check['skipped']++;
                continue;
            }

            $check['statements']++;
            $message = Setup_sql_plan::statement_refusal($statement['text'], $start);

            if ($message !== null) {
                $check = self::add_problem($check, $sql, $statements, $i, $message);
            }
        }

        return $check;
    }

    /**
     * One line saying how it went, for above the problems
     *
     * @param array{statements: int, skipped: int, problems: list<array<string, mixed>>, more_problems: int, fix_lines: list<array{int, int}>, refusal: string|null} $check
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
     * @return array{statements: int, skipped: int, problems: list<array<string, mixed>>, more_problems: int, fix_lines: list<array{int, int}>, refusal: string|null}
     */
    static function empty_check(): array {
        return ['statements' => 0, 'skipped' => 0, 'problems' => [], 'more_problems' => 0, 'fix_lines' => [], 'refusal' => null];
    }

    /**
     * The statements with where each one is in the SQL: the byte its text
     * starts at (leading comments included, as statements() gives them),
     * where its first keyword is, and the lines from that keyword to its end.
     * statements() gives them in order and as typed, so each is found after
     * the one before.
     *
     * @return list<array{text: string, offset: int, keyword_offset: int, first_line: int, last_line: int}>
     */
    static function located_statements(string $sql): array {
        $located = [];
        $from = 0;

        foreach (App_database::statements($sql) as $text) {
            $offset = strpos($sql, $text, $from);

            if ($offset === false) {
                $offset = $from;
            }

            $keyword_offset = $offset + strlen($text) - strlen(App_database::without_comments($text));

            $located[] = [
                'text' => $text,
                'offset' => $offset,
                'keyword_offset' => $keyword_offset,
                'first_line' => self::line_at($sql, $keyword_offset),
                'last_line' => self::line_at($sql, $offset + strlen($text)),
            ];
            $from = $offset + strlen($text);
        }

        return $located;
    }

    /**
     * Add statement $i's problem: where to point, what to say there, and
     * whether commenting it out fixes it
     *
     * @param array{statements: int, skipped: int, problems: list<array<string, mixed>>, more_problems: int, fix_lines: list<array{int, int}>, refusal: string|null} $check
     * @param list<array{text: string, offset: int, keyword_offset: int, first_line: int, last_line: int}> $statements
     * @return array{statements: int, skipped: int, problems: list<array<string, mixed>>, more_problems: int, fix_lines: list<array{int, int}>, refusal: string|null}
     */
    static function add_problem(array $check, string $sql, array $statements, int $i, string $message): array {
        $statement = $statements[$i];
        $problem = self::diagnose($statement['text'], $message);
        $at = $statement['offset'] + $problem['offset'];
        $fixable = $problem['fixable'] && self::owns_its_lines($statements, $i);

        if ($fixable) {
            $check['fix_lines'][] = [$statement['first_line'], $statement['last_line']];
        }

        if (count($check['problems']) >= self::MAX_PROBLEMS) {
            $check['more_problems']++;

            return $check;
        }

        $check['problems'][] = [
            'number' => $i + 1,
            'title' => $problem['title'],
            'line' => self::line_at($sql, $at),
            'column' => self::column_at($sql, $at),
            'length' => $problem['length'],
            'source' => self::line_text($sql, $at),
            'label' => $problem['label'],
            'note' => $problem['note'],
            // "comment it out" only when the button can; other help stands
            'help' => $problem['fixable'] && !$fixable ? null : $problem['help'],
        ];

        return $check;
    }

    /**
     * What to show for a statement the checks refused with $message: a short
     * title, where in the statement to point (byte offset and length) and the
     * words under it, the full rule as a note when the title does not say it,
     * and whether commenting the statement out is the fix
     *
     * @return array{title: string, offset: int, length: int, label: string, note: string|null, help: string|null, fixable: bool}
     */
    static function diagnose(string $statement, string $message): array {
        $keyword_offset = strlen($statement) - strlen(App_database::without_comments($statement));
        $keyword = self::keyword($statement);
        $diagnosis = [
            'title' => rtrim($message, '.'),
            'offset' => $keyword_offset,
            'length' => max(1, strlen($keyword)),
            'label' => 'refused here',
            'note' => null,
            'help' => 'comment this statement out',
            'fixable' => true,
        ];

        $syntax = App_database::syntax_problem($statement);

        if ($syntax !== null && $syntax['message'] === $message) {
            $diagnosis['offset'] = $syntax['offset'];
            $diagnosis['length'] = $message === 'A /* comment is never closed.' ? 2 : 1;
            $diagnosis['label'] = $syntax['label'];
            $diagnosis['help'] = null;
            $diagnosis['fixable'] = false;

            return $diagnosis;
        }

        if ($message === App_database::database_refusal($statement)) {
            $diagnosis['title'] = strtoupper($keyword) . ' is not needed here';
            $diagnosis['label'] = 'your app is already connected to its own database';

            return $diagnosis;
        }

        if ($message === 'This changes your database. Tick "Allow changes" to run it.') {
            $diagnosis['title'] = 'This changes your database';
            $diagnosis['label'] = 'changes data or tables';
            $diagnosis['help'] = 'tick "Allow changes" to run it';
            $diagnosis['fixable'] = false;

            return $diagnosis;
        }

        if ($message === App_database::content_refusal($statement)) {
            $diagnosis['label'] = 'not allowed';

            if (preg_match('#/\*M?!|\bINTO\s+(OUT|DUMP)FILE\b|\bLOAD_FILE\s*\(#i', $statement, $match, PREG_OFFSET_CAPTURE)) {
                $diagnosis['offset'] = $match[0][1];
                $diagnosis['length'] = strlen($match[0][0]);
            }

            return $diagnosis;
        }

        $diagnosis['title'] = ($keyword === '' ? 'This statement' : strtoupper($keyword)) . ' does not run here';
        $diagnosis['label'] = 'not allowed here';
        $diagnosis['note'] = $message;

        return $diagnosis;
    }

    /**
     * The statement's first keyword, two for CREATE DATABASE and the like
     */
    static function keyword(string $statement): string {
        if (preg_match('/^[A-Za-z_]+(\s+(DATABASE|SCHEMA)\b)?/i', App_database::without_comments($statement), $match)) {
            return (string) preg_replace('/\s+/', ' ', $match[0]);
        }

        return '';
    }

    /**
     * Whether statement $i can be commented out line by line without touching
     * another: no other statement's keyword or end shares its lines
     *
     * @param list<array{text: string, offset: int, keyword_offset: int, first_line: int, last_line: int}> $statements
     */
    static function owns_its_lines(array $statements, int $i): bool {
        $before = $statements[$i - 1] ?? null;
        $after = $statements[$i + 1] ?? null;

        if ($before !== null && $before['last_line'] >= $statements[$i]['first_line']) {
            return false;
        }

        if ($after !== null && $after['first_line'] <= $statements[$i]['last_line']) {
            return false;
        }

        return true;
    }

    /**
     * The line (from 1) that byte $offset is on
     */
    static function line_at(string $sql, int $offset): int {
        return substr_count($sql, "\n", 0, min($offset, strlen($sql))) + 1;
    }

    /**
     * The column (from 1, in characters) that byte $offset is at on its line
     */
    static function column_at(string $sql, int $offset): int {
        return mb_strlen(substr($sql, self::line_start($sql, $offset), $offset - self::line_start($sql, $offset))) + 1;
    }

    /**
     * The whole line that byte $offset is on, without its line break
     */
    static function line_text(string $sql, int $offset): string {
        $start = self::line_start($sql, $offset);
        $end = strpos($sql, "\n", $start);
        $line = $end === false ? substr($sql, $start) : substr($sql, $start, $end - $start);

        return rtrim($line, "\r");
    }

    static function line_start(string $sql, int $offset): int {
        $break = strrpos(substr($sql, 0, $offset), "\n");

        return $break === false ? 0 : $break + 1;
    }
}
