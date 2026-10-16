<?php

require_once APPPATH . 'modules/platform/live/Sql_check.php';

/**
 * Answer to a live SQL check (Sql_check), as the HTML shown under the SQL
 * box: views/_sql_check.php. 422 when the real submit would refuse the SQL,
 * 200 when it would not, like a Laravel Precognition request.
 *
 * Used by the Database tab (Database::check) and the new-app wizard
 * (Setup_sql_step::check_setup_sql).
 */
trait Sql_check_response {

    /**
     * @param array{statements: int, skipped: int, problems: list<array{number: int, line: int, preview: string, message: string}>, more_problems: int, refusal: string|null} $check
     */
    function _show_sql_check(array $check): void {
        $refused = $check['refusal'] !== null;
        $summary = Sql_check::summary($check);
        $problems = $this->_sql_check_problems($check['problems']);
        $more_problems = $this->_sql_check_more($check['more_problems']);

        http_response_code($refused ? 422 : 200);
        header('Content-Type: text/html');
        require APPPATH . 'modules/apps/views/_sql_check.php';
    }

    /**
     * Each problem with where it is as one label
     *
     * @param list<array{number: int, line: int, preview: string, message: string}> $problems
     * @return list<array{where: string, preview: string, message: string}>
     */
    function _sql_check_problems(array $problems): array {
        $shown = [];

        foreach ($problems as $problem) {
            $shown[] = [
                'where' => 'Statement ' . $problem['number'] . ', line ' . $problem['line'],
                'preview' => $problem['preview'],
                'message' => $problem['message'],
            ];
        }

        return $shown;
    }

    function _sql_check_more(int $more_problems): ?string {
        if ($more_problems === 0) {
            return null;
        }

        return 'And ' . $more_problems . ' more ' . ($more_problems === 1 ? 'statement' : 'statements') . ' like these.';
    }
}
