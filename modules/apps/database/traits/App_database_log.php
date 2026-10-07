<?php

require_once APPPATH . 'modules/platform/live/App_database.php';

/**
 * Model side of the Database tab's guardrails, shared with the MCP tools:
 * the audit log (app_database_log), the per-customer rate limit and lock,
 * backups taken before changes (app_database_backup), and where an app's
 * database lives.
 *
 * @property-read Db $db
 */
trait App_database_log {

    /** Statements and row changes one customer may make per minute, across all apps */
    const ACTIONS_PER_MINUTE = 30;

    /** Entries shown under "Recent activity" */
    const RECENT_LOG_ENTRIES = 10;

    /** Days a backup is kept */
    const BACKUP_DAYS = 7;

    /** Compressed bytes of backups kept per app, and for all apps; the oldest go first */
    const MAX_APP_BACKUP_BYTES = 64 * 1024 * 1024;
    const MAX_BACKUP_BYTES_TOTAL = 1024 * 1024 * 1024;

    /** Backups listed on the Database tab */
    const BACKUPS_SHOWN = 20;

    /**
     * Record a statement or row change in app_database_log
     *
     * @param array{app_id: int, customer_id: int, action: string, statement: string, allow_changes: bool, affected: int|null, error: string|null} $entry
     * @return int the entry's id
     */
    function log_action(array $entry): int {
        return $this->db->insert([
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

    function backup_days(): int {
        return self::BACKUP_DAYS;
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
                COALESCE(c.github_login, c.email) AS who, b.id AS backup_id
            FROM app_database_log l
            LEFT JOIN customer c ON c.id = l.customer_id
            LEFT JOIN app_database_backup b ON b.log_id = l.id AND b.expires_at > NOW()
            WHERE l.app_id = :app_id
            ORDER BY l.id DESC
            LIMIT %d
        SQL;

        return $this->db->query_bind(sprintf($sql, $limit ?? self::RECENT_LOG_ENTRIES), ['app_id' => $app_id], 'object');
    }

    /**
     * Store a backup taken before a change (App_database::back_up_tables and
     * back_up_row), making room under the size caps by deleting the oldest.
     *
     * @param array{tables: list<array<string, mixed>>, data: string, rows: int, bytes: int} $backup
     * @return int the backup's id
     */
    function save_backup(int $app_id, int $customer_id, string $summary, array $backup): int {
        $this->prune_backups($app_id, strlen($backup['data']));

        $this->db->query_bind(<<<SQL
            INSERT INTO app_database_backup (app_id, customer_id, summary, manifest, data, `rows`, bytes, stored_bytes, expires_at)
            VALUES (:app_id, :customer_id, :summary, :manifest, :data, :rows, :bytes, :stored_bytes, NOW() + INTERVAL :days DAY)
        SQL, [
            'app_id' => $app_id,
            'customer_id' => $customer_id,
            'summary' => mb_strimwidth($summary, 0, 500, '…'),
            'manifest' => serialize($backup['tables']), // keys may be binary, so not JSON
            'data' => $backup['data'],
            'rows' => $backup['rows'],
            'bytes' => $backup['bytes'],
            'stored_bytes' => strlen($backup['data']),
            'days' => self::BACKUP_DAYS,
        ]);

        return (int) $this->db->query('SELECT LAST_INSERT_ID()', 'column');
    }

    /**
     * Point a backup at the log entry of the change it was taken for
     */
    function link_backup(int $backup_id, int $log_id): void {
        $this->db->query_bind('UPDATE app_database_backup SET log_id = :log_id WHERE id = :id', ['log_id' => $log_id, 'id' => $backup_id]);
    }

    /**
     * The app's backups that have not expired, newest first, with the change
     * they were taken for and who made it (no data)
     *
     * @return list<object>
     */
    function backups(int $app_id): array {
        $sql = <<<SQL
            SELECT b.id, b.summary, b.`rows`, b.bytes, b.created_at, b.expires_at, b.restored_at,
                COALESCE(c.github_login, c.email) AS who, l.statement, l.error
            FROM app_database_backup b
            LEFT JOIN customer c ON c.id = b.customer_id
            LEFT JOIN app_database_log l ON l.id = b.log_id
            WHERE b.app_id = :app_id AND b.expires_at > NOW()
            ORDER BY b.id DESC
            LIMIT %d
        SQL;

        return $this->db->query_bind(sprintf($sql, self::BACKUPS_SHOWN), ['app_id' => $app_id], 'object');
    }

    /**
     * One of the app's backups that has not expired, with its data, or false
     */
    function backup(int $app_id, int $backup_id): object|false {
        $rows = $this->db->query_bind(
            'SELECT * FROM app_database_backup WHERE id = :id AND app_id = :app_id AND expires_at > NOW()',
            ['id' => $backup_id, 'app_id' => $app_id],
            'object'
        );

        return $rows[0] ?? false;
    }

    function mark_backup_restored(int $backup_id): void {
        $this->db->query_bind('UPDATE app_database_backup SET restored_at = NOW() WHERE id = :id', ['id' => $backup_id]);
    }

    /**
     * Delete expired backups, then the oldest until $incoming more bytes fit
     * under MAX_APP_BACKUP_BYTES for the app and MAX_BACKUP_BYTES_TOTAL for all.
     */
    function prune_backups(int $app_id, int $incoming): void {
        $this->db->query('DELETE FROM app_database_backup WHERE expires_at <= NOW()');

        foreach ([[self::MAX_APP_BACKUP_BYTES, 'WHERE app_id = ' . $app_id], [self::MAX_BACKUP_BYTES_TOTAL, '']] as [$cap, $where]) {
            $stored = $incoming;
            $delete = [];

            // Newest first: keep while they fit, delete the rest
            foreach ($this->db->query('SELECT id, stored_bytes FROM app_database_backup ' . $where . ' ORDER BY id DESC', 'object') as $backup) {
                $stored += (int) $backup->stored_bytes;

                if ($stored > $cap) {
                    $delete[] = (int) $backup->id;
                }
            }

            if ($delete !== []) {
                $this->db->query('DELETE FROM app_database_backup WHERE id IN (' . implode(', ', $delete) . ')');
            }
        }
    }

    /**
     * The shared MariaDB server an app's database was placed on (see Platform_task::database_server)
     */
    function database_server(int $id): object|false {
        $server = $this->db->get_where($id, 'database_server');

        return is_object($server) ? $server : false;
    }
}
