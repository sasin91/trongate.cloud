<?php

/**
 * Secrets Model
 * Database operations for encrypted secrets storage
 *
 * @property-read Db $db
 */
class Secrets_model extends Model {

    /**
     * Get secrets record for a module
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @return object|null
     */
    function get_secrets(string $module, int $module_id, ?string $service = null): ?object {
        $sql = <<<SQL
            SELECT * FROM secrets
            WHERE module = :module AND module_id = :module_id AND service <=> :service
            LIMIT 1
        SQL;
        $rows = $this->db->query_bind($sql, [
            'module' => $module,
            'module_id' => $module_id,
            'service' => $service,
        ], 'object');

        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Save or update secrets for a module
     * (manual upsert, since the unique key does not dedupe NULL services)
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @param string $encrypted_variables
     * @return bool
     */
    function save_secrets(string $module, int $module_id, ?string $service, string $encrypted_variables): bool {
        $params = [
            'module' => $module,
            'module_id' => $module_id,
            'service' => $service,
            'variables' => $encrypted_variables,
        ];

        if ($this->get_secrets($module, $module_id, $service) !== null) {
            $sql = <<<SQL
                UPDATE secrets SET variables = :variables
                WHERE module = :module AND module_id = :module_id AND service <=> :service
            SQL;
        } else {
            $sql = <<<SQL
                INSERT INTO secrets (module, module_id, service, variables)
                VALUES (:module, :module_id, :service, :variables)
            SQL;
        }

        $this->db->query_bind($sql, $params);

        return true;
    }

    /**
     * Delete secrets for a module
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @return bool
     */
    function delete_secrets(string $module, int $module_id, ?string $service = null): bool {
        $sql = <<<SQL
            DELETE FROM secrets
            WHERE module = :module AND module_id = :module_id AND service <=> :service
        SQL;
        $this->db->query_bind($sql, [
            'module' => $module,
            'module_id' => $module_id,
            'service' => $service,
        ]);

        return true;
    }
}
