<?php

require_once __DIR__ . '/Encryptor.php';

/**
 * Encryption Module
 * Trongate wrapper for the standalone Encryptor class
 */
class Encryption extends Trongate {

    function __construct(?string $module_name = null) {
        parent::__construct($module_name);
        block_url_invocation($module_name);
    }

    function validate_key(string $key): string {
        return Encryptor::validate_key($key);
    }

    /**
     * Encrypt a string
     * @param string $plaintext
     * @param string $key
     * @return string Base64 encoded (nonce + ciphertext)
     */
    function encrypt(string $plaintext, string $key): string {
        return Encryptor::encrypt($plaintext, $key);
    }

    /**
     * Decrypt a string
     * @param string $encrypted Base64 encoded (nonce + ciphertext)
     * @param string $key
     * @return string|false Plaintext or false on failure
     */
    function decrypt(string $encrypted, string $key): string|false {
        return Encryptor::decrypt($encrypted, $key);
    }

    /**
     * Generate a new encryption key
     * @return string Base64 encoded key
     */
    static function generate_key(): string {
        return Encryptor::generate_key();
    }
}
