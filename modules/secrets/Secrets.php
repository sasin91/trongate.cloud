<?php

/**
 * Secrets Controller
 * Generic encrypted key-value storage for any module
 * @property-read Secrets_model $model
 * @property-read Encryption $encryption
 */
class Secrets extends Trongate {

    function __construct(?string $module_name = null) {
        parent::__construct($module_name);
        block_url_invocation($module_name);
    }

    /**
     * Get decrypted secrets for a module
     * @param string $module e.g. 'server', 'project', 'team'
     * @param int $module_id
     * @param string|null $service e.g. 'hetzner', 'github' (optional)
     * @return array Key-value pairs or empty array
     */
    function get(string $module, int $module_id, ?string $service = null): array {
        $row = $this->model->get_secrets($module, $module_id, $service);

        if ($row === null) {
            http_response_code(404);
            $this->view('error_404', [
                'content' => <<<HTML
                    <h2>Secret not found</h2>
                    <p>No secrets found for module</p>
                HTML
            ]);
            die();
        }

        $this->module('encryption');
        $decrypted = $this->encryption->decrypt($row->variables, $row->encryption_key);

        if ($decrypted === false) {
            http_response_code(422);
            $this->view('error_422', [
                'content' => <<<HTML
                    <h2>Unable to decrypt secret</h2>
                    <p>The encryption key is invalid or the secret has been corrupted.</p>
                HTML
            ]);
        }

        return json_decode($decrypted, true) ?: [];
    }

    /**
     * Save secrets for a module (replaces all existing)
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @param string $encryption_key
     * @param array $variables Key-value pairs
     * @return bool
     */
    function save(string $module, int $module_id, ?string $service, string $encryption_key, array $variables): bool {
        $this->module('encryption');
        $encrypted = $this->encryption->encrypt(json_encode($variables), $encryption_key);

        return $this->model->save_secrets($module, $module_id, $service, $encryption_key, $encrypted);
    }

    /**
     * Update specific keys (merge with existing)
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @param array $variables Key-value pairs to merge
     * @return bool
     */
    function update(string $module, int $module_id, ?string $service, array $variables): bool {
        $existing = $this->get($module, $module_id, $service);
        $merged = array_merge($existing, $variables);
        return $this->save($module, $module_id, $service, $existing['encryption_key'], $merged);
    }

    /**
     * Delete all secrets for a module
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @return bool
     */
    function delete(string $module, int $module_id, ?string $service = null): bool {
        return $this->model->delete_secrets($module, $module_id, $service);
    }

    /**
     * Check if module has secrets
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @return bool
     */
    function exists(string $module, int $module_id, ?string $service = null): bool {
        return $this->model->get_secrets($module, $module_id, $service) !== null;
    }
}
