<?php

require_once APPPATH . 'modules/platform/live/App_database.php';

/**
 * Model side of the Database tab's guardrails, shared with the MCP tools:
 * the audit log (app_database_log), the per-customer rate limit and lock,
 * and where an app's database lives.
 *
 * @property-read Db $db
 */
trait App_database_log {

    /** Statements and row changes one customer may make per minute, across all apps */
    const ACTIONS_PER_MINUTE = 30;

    /** Entries shown under "Recent activity" */
    const RECENT_LOG_ENTRIES = 10;

    /**
     * Record a statement or row change in app_database_log
     *
     * @param array{app_id: int, customer_id: int, action: string, statement: string, allow_changes: bool, affected: int|null, error: string|null} $entry
     */
    function log_action(array $entry): void {
        $this->db->insert([
            'app_id' => $entry['app_id'],
            'customer_id' => $entry['customer_id'],
            'action' => $entry['action'],
            'statement' => substr($entry['statement'], 0, App_database::MAX_STATEMENT_BYTES),
            'allow_changes' => $entry['allow_changes'] ? 1 : 0,
            'affected' => $entry['affected'],
            'error' => $entry['error'] === null ? null : substr($entry['error'], 0, 500),
        ], 'app_database_log');
    }

    /**
     * Take the customer's lock: one statement at a time per customer, so a few
     * slow queries cannot tie up every web worker. False when already held.
     * Released by release_action_lock() or when the request's connection closes.
     */
    function take_action_lock(int $customer_id): bool {
        $rows = $this->db->query_bind('SELECT GET_LOCK(:name, 0) AS got', ['name' => 'app_database:' . $customer_id], 'object');

        return (int) ($rows[0]->got ?? 0) === 1;
    }

    function release_action_lock(int $customer_id): void {
        $this->db->query_bind('SELECT RELEASE_LOCK(:name) AS released', ['name' => 'app_database:' . $customer_id], 'object');
    }

    function actions_per_minute(): int {
        return self::ACTIONS_PER_MINUTE;
    }

    /**
     * Whether the customer has used up ACTIONS_PER_MINUTE
     */
    function over_action_limit(int $customer_id): bool {
        $rows = $this->db->query_bind(
            'SELECT COUNT(*) AS n FROM app_database_log WHERE customer_id = :customer_id AND created_at > NOW() - INTERVAL 1 MINUTE',
            ['customer_id' => $customer_id],
            'object'
        );

        return (int) ($rows[0]->n ?? 0) >= self::ACTIONS_PER_MINUTE;
    }

    /**
     * The app's latest log entries, newest first, with who made them
     *
     * @return list<object>
     */
    function recent_actions(int $app_id, ?int $limit = null): array {
        $sql = <<<SQL
            SELECT l.action, l.statement, l.affected, l.error, l.created_at,
                COALESCE(c.github_login, c.email) AS who
            FROM app_database_log l
            LEFT JOIN customer c ON c.id = l.customer_id
            WHERE l.app_id = :app_id
            ORDER BY l.id DESC
            LIMIT %d
        SQL;

        return $this->db->query_bind(sprintf($sql, $limit ?? self::RECENT_LOG_ENTRIES), ['app_id' => $app_id], 'object');
    }

    /**
     * The shared MariaDB server an app's database was placed on (see Platform_task::database_server)
     */
    function database_server(int $id): object|false {
        $server = $this->db->get_where($id, 'database_server');

        return is_object($server) ? $server : false;
    }
}
