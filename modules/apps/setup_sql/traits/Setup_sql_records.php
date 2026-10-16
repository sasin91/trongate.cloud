<?php

require_once APPPATH . 'modules/platform/live/Setup_sql_plan.php';

/**
 * The app's setup SQL (app_setup_sql): what the new-app wizard stored, its
 * status after the first deploy ran it, and the failed alert's dismissal.
 * Used through App_records, so onboarding writes it on the connection that
 * creates the app (inside its transaction).
 *
 * @property-read Db $db
 */
trait Setup_sql_records {

    /**
     * The SQL the app's database starts with (Setup_sql_plan, encrypted); its
     * first deploy runs it
     */
    function set_setup_sql(int $app_id, string $encrypted_statements): void {
        $this->db->query_bind(
            "INSERT INTO app_setup_sql (app_id, statements, status, error) VALUES (:app_id, :statements, 'pending', NULL)
             ON DUPLICATE KEY UPDATE statements = VALUES(statements), status = 'pending', error = NULL",
            ['app_id' => $app_id, 'statements' => $encrypted_statements]
        );
    }

    /**
     * The app's setup SQL (statements still encrypted), or false when the
     * wizard had none
     */
    function app_setup_sql(int $app_id): object|false {
        $rows = $this->db->query_bind('SELECT statements, status, error FROM app_setup_sql WHERE app_id = :app_id', ['app_id' => $app_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * Why the app's setup SQL stopped and the SQL that did not run, or
     * null when it ran (or there was none)
     *
     * @return array{message: string, remaining_sql: string}|null
     */
    function setup_sql_failure(int $app_id): ?array {
        $setup_sql = $this->app_setup_sql($app_id);

        if ($setup_sql === false || $setup_sql->status !== 'failed') {
            return null;
        }

        $remaining_sql = Setup_sql_plan::decrypt((string) $setup_sql->statements);

        if ($remaining_sql === null) {
            $remaining_sql = '-- It could not be decrypted (APP_KEY changed).';
        }

        return [
            'message' => (string) $setup_sql->error,
            'remaining_sql' => $remaining_sql,
        ];
    }

    /**
     * Hide a failed setup SQL's alert: the statements that did not run are
     * cleared, the error kept
     */
    function dismiss_setup_sql(int $app_id): void {
        $this->db->query_bind("UPDATE app_setup_sql SET statements = NULL, status = 'dismissed' WHERE app_id = :app_id AND status = 'failed'", ['app_id' => $app_id]);
    }
}
