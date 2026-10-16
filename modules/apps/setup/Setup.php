<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/platform/live/Setup_sql.php';

/**
 * Child module of apps: the SQL an app's database is set up with, once it
 * has run. The new-app wizard's Database question asks for it (App_setup_sql)
 * and the first deploy runs it (Platform_task::run_setup_sql); when that
 * stops part way, the overview shows why and the SQL that did not run
 * (_failure, views/_failure.php) until it is dismissed. Every member of the
 * app's project may use it.
 *
 * Named apps-setup, not apps-setup_sql: its class would clash with Setup_sql.
 *
 * @property-read Setup_model $model
 */
class Setup extends Trongate {
    use Customer_guard;

    /**
     * POST apps/{id}/setup-sql/dismiss: hide the alert about a failed setup SQL
     */
    function dismiss(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $this->model->dismiss_setup_sql((int) $app->id);

        redirect('apps/' . $app->id);
    }

    /**
     * Why the app's setup SQL stopped and the SQL that did not run, or
     * null when it ran (or there was none). For views/_failure.php.
     *
     * @return array{message: string, remaining_sql: string}|null
     */
    function _failure(object $app): ?array {
        if (($app->setup_sql_status ?? null) !== 'failed') {
            return null;
        }

        $remaining_sql = Setup_sql::decrypt((string) $app->setup_sql);

        if ($remaining_sql === null) {
            $remaining_sql = '-- It could not be decrypted (APP_KEY changed).';
        }

        return [
            'message' => (string) $app->setup_sql_error,
            'remaining_sql' => $remaining_sql,
        ];
    }
}
