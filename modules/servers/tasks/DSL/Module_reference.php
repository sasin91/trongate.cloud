<?php

require_once APPPATH . 'modules/encryption/Encryptor.php';

/**
 * Module Reference DSL
 * 
 * Parses and resolves reference syntax like:
 *   secrets:server,123,db_project_1.db_password
 * 
 * Extensible to future reference types (env:, vault:, etc.)
 */
class Module_reference
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Parse reference into [type, args]
     * @return array{0: string, 1: string}
     */
    public function parse(string $syntax): array
    {
        !str_contains($syntax, ':') && throw new InvalidArgumentException("Invalid reference syntax: {$syntax}");

        return explode(':', $syntax, 2);
    }

    /**
     * Resolve the reference to its actual value
     */
    public function resolve(string $syntax): mixed
    {
        [$type, $args] = $this->parse($syntax);

        $method = "resolve_{$type}";

        return $this->$method($args);
    }

    /**
     * Resolve a secrets reference
     * Format: module,module_id,service
     */
    public function resolve_secrets(string $args): false|array
    {
        [$module, $module_id, $service] = explode(',', $args);
        $module_id = (int) $module_id;

        // Query secrets table
        $stmt = $this->db->prepare(
            'SELECT encryption_key, variables FROM secrets
             WHERE module = ? AND module_id = ? AND service = ?
             LIMIT 1'
        );
        $stmt->bind_param('sis', $module, $module_id, $service);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        // Decrypt using Encryptor
        $decrypted = Encryptor::decrypt($row['variables'], $row['encryption_key']);
        if ($decrypted === false) {
            return false;
        }

        return json_decode($decrypted, true);
    }
}
