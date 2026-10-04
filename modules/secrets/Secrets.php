<?php

require_once __DIR__ . '/../encryption/Encryptor.php';

/**
 * Secrets Controller
 * Generic encrypted key-value storage for any module.
 * All secrets are encrypted with APP_KEY.
 * @property-read Secrets_model $model
 */
class Secrets extends Trongate {

    function __construct(?string $module_name = null) {
        parent::__construct($module_name);
        block_url('secrets');
    }

    /**
     * Get decrypted secrets for a module
     * @param string $module e.g. 'server', 'app', 'project'
     * @param int $module_id
     * @param string|null $service e.g. 'hetzner', 'github' (optional)
     * @return array Key-value pairs
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

        $decrypted = Encryptor::decrypt($row->variables, APP_KEY);

        if ($decrypted === false) {
            http_response_code(422);
            $this->view('error_422', [
                'content' => <<<HTML
                    <h2>Unable to decrypt secret</h2>
                    <p>The encryption key is invalid or the secret has been corrupted.</p>
                HTML
            ]);
            die();
        }

        return json_decode($decrypted, true) ?: [];
    }

    /**
     * Save secrets for a module (replaces all existing)
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @param array $variables Key-value pairs
     * @return bool
     */
    function save(string $module, int $module_id, ?string $service, array $variables): bool {
        $encrypted = Encryptor::encrypt(json_encode($variables), APP_KEY);

        return $this->model->save_secrets($module, $module_id, $service, $encrypted);
    }

    /**
     * Update specific keys (merge with existing, creates if missing)
     * @param string $module
     * @param int $module_id
     * @param string|null $service
     * @param array $variables Key-value pairs to merge
     * @return bool
     */
    function update(string $module, int $module_id, ?string $service, array $variables): bool {
        $existing = $this->exists($module, $module_id, $service)
            ? $this->get($module, $module_id, $service)
            : [];

        return $this->save($module, $module_id, $service, array_merge($existing, $variables));
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
