<?php

require_once APPPATH . 'modules/encryption/Encryptor.php';

/**
 * Module Reference DSL
 *
 * Parses and resolves reference syntax like:
 *   secrets:customer,12,github
 *
 * Extensible to future reference types (env:, vault:, etc.)
 */
class Module_reference {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Parse reference into [type, args]
     * @return array{0: string, 1: string}
     */
    public function parse(string $syntax): array {
        !str_contains($syntax, ':') && throw new InvalidArgumentException("Invalid reference syntax: {$syntax}");

        return explode(':', $syntax, 2);
    }

    /**
     * Resolve the reference to its actual value
     */
    public function resolve(string $syntax): mixed {
        [$type, $args] = $this->parse($syntax);

        $method = "resolve_{$type}";
        if (!preg_match('/^[a-z]+$/', $type) || !method_exists($this, $method)) {
            throw new InvalidArgumentException("Unknown reference type: {$type}");
        }

        return $this->$method($args);
    }

    /**
     * Resolve a secrets reference
     * Format: module,module_id[,service]
     *
     * Decrypts with APP_KEY, matching the Secrets module.
     */
    public function resolve_secrets(string $args): false|array {
        [$module, $module_id, $service] = array_pad(explode(',', $args, 3), 3, null);
        $module_id = (int) $module_id;

        // <=> so a missing service matches rows stored with service NULL
        $row = Runtime::run(
            $this->db,
            'SELECT variables FROM secrets
             WHERE module = ? AND module_id = ? AND service <=> ?
             LIMIT 1',
            [$module, $module_id, $service]
        )->fetch();

        if ($row === false) {
            return false;
        }

        $decrypted = Encryptor::decrypt($row['variables'], getenv('APP_KEY') ?: '');
        if ($decrypted === false) {
            return false;
        }

        return json_decode($decrypted, true);
    }
}
