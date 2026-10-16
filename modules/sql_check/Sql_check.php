<?php

require_once APPPATH . 'modules/platform/live/Sql_checker.php';

/**
 * SQL checked while it is typed or pasted, for any SQL box in the panel:
 * the Database tab's SQL box and the new-app wizard's typed SQL today. Like
 * Laravel Precognition, it runs the checks the box's real submit runs
 * (Sql_checker) and answers with what they found, drawn like the Rust
 * compiler's errors (views/_result.php), without running the SQL anywhere.
 *
 * A box opts in with data-sql-check (this URL), data-sql-check-rules (one
 * of RULES) and data-sql-check-result (where the answer goes), and loads
 * js/sql_check.js and css/sql_check.css.
 *
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Validation $validation
 */
class Sql_check extends Trongate {

    /** Which submit's checks to run: the Database tab's SQL box, or SQL a new app starts with */
    const RULES = ['database', 'setup'];

    /**
     * POST sql/check with sql (as typed, so the lines it names are the
     * box's lines), rules and allow_changes: the answer as HTML, 422 when the
     * real submit would refuse the SQL, 200 when it would not. Any signed-in
     * customer may ask, onboarding ones included; nothing is stored or run.
     */
    function check(): void {
        if (REQUEST_TYPE !== 'POST') {
            http_response_code(405);
            die('Method not allowed');
        }

        if ($this->trongate_tokens->attempt_get_valid_token(2) === false) {
            http_response_code(401);
            die();
        }

        $this->validation->run(); // CSRF

        $sql = (string) post('sql');
        $rules = (string) post('rules', true);

        if (!in_array($rules, self::RULES, true)) {
            http_response_code(400);
            die();
        }

        if ($rules === 'setup') {
            $this->_show(Sql_checker::setup($sql));
        } else {
            $this->_show(Sql_checker::database_tab($sql, (bool) post('allow_changes')));
        }
    }

    /**
     * @param array{statements: int, skipped: int, problems: list<array<string, mixed>>, more_problems: int, fix_lines: list<array{int, int}>, refusal: string|null} $check
     */
    function _show(array $check): void {
        $data = [
            'refused' => $check['refusal'] !== null,
            'summary' => Sql_checker::summary($check),
            'problems' => $this->_problems($check['problems']),
            'more_problems' => $this->_more_problems($check['more_problems']),
            'fix_lines' => $this->_fix_lines($check['fix_lines']),
            'fix_label' => $this->_fix_label(count($check['fix_lines'])),
        ];

        http_response_code($data['refused'] ? 422 : 200);
        header('Content-Type: text/html');
        $this->view('_result', $data);
    }

    /**
     * Each problem as its title, where it is, and the rows under them
     *
     * @param list<array<string, mixed>> $problems
     * @return list<array{title: string, where: string, rows: list<array{gutter: string, pipe: string, text: string, kind: string}>}>
     */
    function _problems(array $problems): array {
        $shown = [];

        foreach ($problems as $problem) {
            $shown[] = [
                'title' => (string) $problem['title'],
                'where' => 'line ' . $problem['line'] . ', column ' . $problem['column'] . ' (statement ' . $problem['number'] . ')',
                'rows' => $this->_rows($problem),
            ];
        }

        return $shown;
    }

    /**
     * The line with its number, ^^^ under the refused part and what it is,
     * then note and help set off without the |, as rustc does. Gutters are
     * padded to one width.
     *
     * @param array<string, mixed> $problem
     * @return list<array{gutter: string, pipe: string, text: string, kind: string}>
     */
    function _rows(array $problem): array {
        $number = (string) $problem['line'];
        $blank = str_repeat(' ', strlen($number));
        $source = (string) $problem['source'];
        $rows = [];

        $rows[] = ['gutter' => $blank, 'pipe' => '|', 'text' => '', 'kind' => 'blank'];
        $rows[] = ['gutter' => $number, 'pipe' => '|', 'text' => $source, 'kind' => 'code'];
        $rows[] = ['gutter' => $blank, 'pipe' => '|', 'text' => $this->_marker($source, (int) $problem['column'], (int) $problem['length'], (string) $problem['label']), 'kind' => 'mark'];

        if ($problem['note'] !== null) {
            $rows[] = ['gutter' => $blank, 'pipe' => ' ', 'text' => '= note: ' . $problem['note'], 'kind' => 'note'];
        }

        if ($problem['help'] !== null) {
            $rows[] = ['gutter' => $blank, 'pipe' => ' ', 'text' => '= help: ' . $problem['help'], 'kind' => 'help'];
        }

        return $rows;
    }

    /**
     * ^^^ under $length characters from $column, with the label after. Tabs
     * before it stay tabs, so it lines up with the line above.
     */
    function _marker(string $source, int $column, int $length, string $label): string {
        $before = mb_substr($source, 0, $column - 1);
        $padding = (string) preg_replace('/[^\t]/u', ' ', $before);

        return $padding . str_repeat('^', $length) . ' ' . $label;
    }

    function _more_problems(int $more_problems): ?string {
        if ($more_problems === 0) {
            return null;
        }

        return 'And ' . $more_problems . ' more ' . ($more_problems === 1 ? 'statement' : 'statements') . ' like these.';
    }

    /**
     * The lines "Comment out" puts -- before, as "6-6,12-15" for sql_check.js
     *
     * @param list<array{int, int}> $fix_lines
     */
    function _fix_lines(array $fix_lines): string {
        $ranges = [];

        foreach ($fix_lines as $range) {
            $ranges[] = $range[0] . '-' . $range[1];
        }

        return implode(',', $ranges);
    }

    /**
     * The button's text, or null when commenting out fixes nothing
     */
    function _fix_label(int $statements): ?string {
        if ($statements === 0) {
            return null;
        }

        return $statements === 1 ? 'Comment out this statement' : 'Comment out these ' . $statements . ' statements';
    }
}
