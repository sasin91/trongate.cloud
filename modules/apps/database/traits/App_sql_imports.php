<?php

require_once APPPATH . 'modules/platform/live/Sql_import.php';

/**
 * Model side of the Database tab's .sql file imports (Sql_import): the
 * import rows, their stored parts, and the worker tasks that check and run them.
 *
 * The using model also uses Platform_actions (_queue_task).
 *
 * @property-read Db $db
 */
trait App_sql_imports {

    /**
     * The app's latest import, or false
     */
    function latest_import(int $app_id): object|false {
        $rows = $this->db->query_bind('SELECT * FROM app_sql_import WHERE app_id = :app_id ORDER BY id DESC LIMIT 1', ['app_id' => $app_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * One of the app's imports, or false
     */
    function sql_import(int $app_id, int $import_id): object|false {
        $rows = $this->db->query_bind('SELECT * FROM app_sql_import WHERE id = :id AND app_id = :app_id', ['id' => $import_id, 'app_id' => $app_id], 'object');

        return $rows[0] ?? false;
    }

    /**
     * Start an upload. Earlier imports of the app that are not checking or
     * running are discarded: one file at a time per app.
     */
    function create_import(int $app_id, int $customer_id, string $file_name, int $bytes): int {
        $this->db->query_bind(
            "DELETE FROM app_sql_import WHERE app_id = :app_id AND status IN ('uploading', 'checked', 'failed', 'stopped')",
            ['app_id' => $app_id]
        );

        return $this->db->insert([
            'app_id' => $app_id,
            'customer_id' => $customer_id,
            'file_name' => $file_name,
            'bytes' => $bytes,
            'status' => 'uploading',
        ], 'app_sql_import');
    }

    /**
     * Store part $seq of an upload (again, when the browser retried it)
     */
    function save_chunk(int $import_id, int $seq, string $bytes): void {
        $this->db->query_bind(
            'REPLACE INTO app_sql_import_chunk (import_id, seq, bytes, data) VALUES (:import_id, :seq, :bytes, :data)',
            ['import_id' => $import_id, 'seq' => $seq, 'bytes' => strlen($bytes), 'data' => Sql_import::seal($bytes)]
        );

        if ($seq === 0) {
            $this->db->update($import_id, ['compressed' => Sql_import::is_gzip($bytes) ? 1 : 0], 'app_sql_import');
        }
    }

    /**
     * Parts of an upload stored so far, and their bytes
     *
     * @return array{chunks: int, bytes: int}
     */
    function received(int $import_id): array {
        $rows = $this->db->query_bind(
            'SELECT COUNT(*) AS chunks, COALESCE(SUM(bytes), 0) AS bytes FROM app_sql_import_chunk WHERE import_id = :import_id',
            ['import_id' => $import_id],
            'object'
        );

        return ['chunks' => (int) ($rows[0]->chunks ?? 0), 'bytes' => (int) ($rows[0]->bytes ?? 0)];
    }

    /**
     * Change an import's status (and other columns) and queue a worker task
     * to check or run it
     *
     * @param array<string, mixed> $fields
     */
    function queue_import(object $app, int $import_id, array $fields): void {
        $this->db->update($import_id, $fields, 'app_sql_import');
        $this->_queue_task((int) $app->project_id, (int) $app->id, null, 'import_sql', [
            'import_id' => $import_id,
            'app_id' => (int) $app->id,
            'project_id' => (int) $app->project_id,
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    function update_import(int $import_id, array $fields): void {
        $this->db->update($import_id, $fields, 'app_sql_import');
    }

    /**
     * Stop an import that is checking or running; false when it was not
     */
    function stop_import(int $import_id): bool {
        $this->db->query_bind(
            "UPDATE app_sql_import SET status = 'stopped', completed_at = NOW() WHERE id = :id AND status IN ('checking', 'running')",
            ['id' => $import_id]
        );
        $rows = $this->db->query_bind('SELECT status FROM app_sql_import WHERE id = :id', ['id' => $import_id], 'object');

        return ($rows[0]->status ?? '') === 'stopped';
    }

    /**
     * Delete an import and its stored file (not while it is checking or running)
     */
    function discard_import(int $import_id): void {
        $this->db->query_bind(
            "DELETE FROM app_sql_import WHERE id = :id AND status NOT IN ('checking', 'running')",
            ['id' => $import_id]
        );
    }
}
