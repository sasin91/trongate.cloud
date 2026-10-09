<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';

/**
 * Captured mail of an app (app_mail, written by the inbox's Mail_store)
 *
 * @property-read Db $db
 */
class Mail_model extends Model {
    use Current_customer;
    use Platform_actions;

    /** Messages listed on the Mail tab, newest first */
    const LISTED = 100;

    /**
     * The app's newest messages, without their source
     *
     * @return list<object>
     */
    function messages(int $app_id): array {
        $sql = <<<SQL
            SELECT id, mail_from, recipients, from_header, subject, bytes, created_at
            FROM app_mail
            WHERE app_id = :app_id
            ORDER BY id DESC
            LIMIT :listed
        SQL;

        return $this->db->query_bind($sql, ['app_id' => $app_id, 'listed' => self::LISTED], 'object') ?: [];
    }

    /**
     * How many messages the app has, and the newest one's id (0 for none)
     *
     * @return array{count: int, latest: int}
     */
    function summary(int $app_id): array {
        $rows = $this->db->query_bind('SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS latest FROM app_mail WHERE app_id = :app_id', ['app_id' => $app_id], 'object');

        return ['count' => (int) ($rows[0]->n ?? 0), 'latest' => (int) ($rows[0]->latest ?? 0)];
    }

    /**
     * One of the app's messages, with its source
     */
    function message(int $app_id, int $mail_id): object|false {
        $rows = $this->db->query_bind('SELECT * FROM app_mail WHERE id = :id AND app_id = :app_id', ['id' => $mail_id, 'app_id' => $app_id], 'object');

        return $rows[0] ?? false;
    }

    function delete_message(int $app_id, int $mail_id): void {
        $this->db->query_bind('DELETE FROM app_mail WHERE id = :id AND app_id = :app_id', ['id' => $mail_id, 'app_id' => $app_id]);
    }

    function delete_all(int $app_id): void {
        $this->db->query_bind('DELETE FROM app_mail WHERE app_id = :app_id', ['app_id' => $app_id]);
    }
}
