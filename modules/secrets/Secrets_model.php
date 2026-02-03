<?php

/**
 * Secrets Model
 * Database operations for encrypted secrets storage
 */
class Secrets_model extends Model
{

    /**
     * Get secrets record for a module
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @return object|null
     */
    public function get_secrets(string $module, int $module_id, ?string $service = null): ?object
    {
        if ($service !== null) {
            $sql = "SELECT * FROM secrets
                    WHERE module = :module AND module_id = :module_id AND service = :service
                    LIMIT 1";
            $rows = $this->db->query_bind($sql, [
                'module' => $module,
                'module_id' => $module_id,
                'service' => $service,
            ], 'object');
        } else {
            $sql = "SELECT * FROM secrets
                    WHERE module = :module AND module_id = :module_id AND service IS NULL
                    LIMIT 1";
            $rows = $this->db->query_bind($sql, [
                'module' => $module,
                'module_id' => $module_id,
            ], 'object');
        }

        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Save or update secrets for a module
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @param string $encryption_key
     * @param string $encrypted_variables
     * @return bool
     */
    public function save_secrets(string $module, int $module_id, ?string $service, string $encryption_key, string $encrypted_variables): bool
    {
        $sql = "INSERT INTO secrets (encryption_key, module, module_id, service, variables)
                VALUES (:encryption_key, :module, :module_id, :service, :encrypted_variables)
                ON DUPLICATE KEY UPDATE
                variables = :encrypted_variables";

        $this->db->query_bind($sql, [
            'encryption_key' => $encryption_key,
            'module' => $module,
            'module_id' => $module_id,
            'service' => $service,
            'encrypted_variables' => $encrypted_variables,
        ]);

        return true;
    }

    /**
     * Delete secrets for a module
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @return bool
     */
    public function delete_secrets(string $module, int $module_id, ?string $service = null): bool
    {
        if ($service !== null) {
            $sql = "DELETE FROM secrets
                    WHERE module = :module AND module_id = :module_id AND service = :service";
            $this->db->query_bind($sql, [
                'module' => $module,
                'module_id' => $module_id,
                'service' => $service,
            ]);
        } else {
            $sql = "DELETE FROM secrets
                    WHERE module = :module AND module_id = :module_id AND service IS NULL";
            $this->db->query_bind($sql, [
                'module' => $module,
                'module_id' => $module_id,
            ]);
        }

        return true;
    }
}
