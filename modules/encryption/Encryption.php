<?php
/**
 * Encryption Module
 * libsodium wrapper for encrypting sensitive data at rest
 */
class Encryption extends Trongate {

    public function __construct(?string $module_name = null) {
        parent::__construct($module_name);
        block_url_invocation($module_name);
    }

    public function validate_key(string $key): string
    {
        if (empty($key)) {
            throw new InvalidArgumentException('key must not be empty.');
        }

        $decoded = base64_decode($key, true);

        if ($decoded !== false) {
            $key = $decoded;
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException(
                $decoded === false 
                    ? 'key must be 32 bytes'
                    : 'key must be 32 bytes (base64 encoded)'
                );
        }

        return $key;
    }

    /**
     * Encrypt a string
     * @param string $plaintext
     * @param string $key
     * @return string Base64 encoded (nonce + ciphertext)
     */
    public function encrypt(string $plaintext, string $key): string {
        $key = $this->validate_key($key);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        // Prepend nonce to ciphertext
        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt a string
     * @param string $encrypted Base64 encoded (nonce + ciphertext)
     * @param string $key
     * @return string|false Plaintext or false on failure
     */
    public function decrypt(string $encrypted, string $key): string|false {
        $decoded = base64_decode($encrypted);
        if ($decoded === false) {
            return false;
        }

        if (strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }

        $key = $this->validate_key($key);
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        return $plaintext;
    }

    /**
     * Generate a new encryption key
     * @return string Base64 encoded key
     */
    public static function generate_key(): string {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    /**
     * Generate a secure random password
     * @param int $length Number of bytes (output will be hex, so 2x length)
     * @return string Hex encoded password
     */
    public static function generate_password(int $length = 32): string {
        return sodium_bin2hex(random_bytes($length));
    }

    /**
     * Hash a password for storage
     * @param string $password
     * @return string
     */
    public static function hash_password(string $password): string {
        return sodium_crypto_pwhash_str(
            $password,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );
    }

    /**
     * Verify a password against a hash
     * @param string $password
     * @param string $hash
     * @return bool
     */
    public static function verify_password(string $password, string $hash): bool {
        return sodium_crypto_pwhash_str_verify($hash, $password);
    }
}
