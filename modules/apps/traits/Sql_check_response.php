<?php

require_once APPPATH . 'modules/platform/live/Sql_check.php';

/**
 * Answer to a live SQL check (Sql_check), as the HTML shown under the SQL
 * box: views/_sql_check.php. 422 when the real submit would refuse the SQL,
 * 200 when it would not, like a Laravel Precognition request.
 *
 * Problems are drawn the way the Rust compiler draws errors: the title,
 * where it is, the line itself with ^^^ under the part refused, then a
 * note and a help line, set off without the | as rustc does. "Comment out" puts -- before the lines in fix_lines.
 *
 * Used by the Database tab (Database::check) and the new-app wizard
 * (Setup_sql_step::check_setup_sql).
 */
trait Sql_check_response {

    /**
     * @param array{statements: int, skipped: int, problems: list<array<string, mixed>>, more_problems: int, fix_lines: list<array{int, int}>, refusal: string|null} $check
     */
    function _show_sql_check(array $check): void {
        $refused = $check['refusal'] !== null;
        $summary = Sql_check::summary($check);
        $problems = $this->_sql_check_problems($check['problems']);
        $more_problems = $this->_sql_check_more($check['more_problems']);
        $fix_lines = $this->_sql_check_fix_lines($check['fix_lines']);
        $fix_label = $this->_sql_check_fix_label(count($check['fix_lines']));

        http_response_code($refused ? 422 : 200);
        header('Content-Type: text/html');
        require APPPATH . 'modules/apps/views/_sql_check.php';
    }

    /**
     * Each problem as its title, where it is, and the rows under them
     *
     * @param list<array<string, mixed>> $problems
     * @return list<array{title: string, where: string, rows: list<array{gutter: string, text: string, kind: string, pipe: string}>}>
     */
    function _sql_check_problems(array $problems): array {
        $shown = [];

        foreach ($problems as $problem) {
            $shown[] = [
                'title' => (string) $problem['title'],
                'where' => 'line ' . $problem['line'] . ', column ' . $problem['column'] . ' (statement ' . $problem['number'] . ')',
                'rows' => $this->_sql_check_rows($problem),
            ];
        }

        return $shown;
    }

    /**
     * The line with its number, ^^^ under the refused part and what it is,
     * then note and help when there are. Gutters are padded to one width.
     *
     * @param array<string, mixed> $problem
     * @return list<array{gutter: string, text: string, kind: string, pipe: string}>
     */
    function _sql_check_rows(array $problem): array {
        $number = (string) $problem['line'];
        $width = strlen($number);
        $source = (string) $problem['source'];
        $rows = [];

        $rows[] = ['gutter' => str_repeat(' ', $width), 'text' => '', 'kind' => 'blank', 'pipe' => '|'];
        $rows[] = ['gutter' => $number, 'text' => $source, 'kind' => 'code', 'pipe' => '|'];
        $rows[] = ['gutter' => str_repeat(' ', $width), 'text' => $this->_sql_check_marker($source, (int) $problem['column'], (int) $problem['length'], (string) $problem['label']), 'kind' => 'mark', 'pipe' => '|'];

        if ($problem['note'] !== null) {
            $rows[] = ['gutter' => str_repeat(' ', $width), 'text' => '= note: ' . $problem['note'], 'kind' => 'note', 'pipe' => ' '];
        }

        if ($problem['help'] !== null) {
            $rows[] = ['gutter' => str_repeat(' ', $width), 'text' => '= help: ' . $problem['help'], 'kind' => 'help', 'pipe' => ' '];
        }

        return $rows;
    }

    /**
     * ^^^ under $length characters from $column, with the label after. Tabs
     * before it stay tabs, so it lines up with the line above.
     */
    function _sql_check_marker(string $source, int $column, int $length, string $label): string {
        $before = mb_substr($source, 0, $column - 1);
        $padding = (string) preg_replace('/[^\t]/u', ' ', $before);

        return $padding . str_repeat('^', $length) . ' ' . $label;
    }

    function _sql_check_more(int $more_problems): ?string {
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
    function _sql_check_fix_lines(array $fix_lines): string {
        $ranges = [];

        foreach ($fix_lines as $range) {
            $ranges[] = $range[0] . '-' . $range[1];
        }

        return implode(',', $ranges);
    }

    /**
     * The button's text, or null when commenting out fixes nothing
     */
    function _sql_check_fix_label(int $statements): ?string {
        if ($statements === 0) {
            return null;
        }

        return $statements === 1 ? 'Comment out this statement' : 'Comment out these ' . $statements . ' statements';
    }
}
