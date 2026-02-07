<?php

/**
 * Standalone Encryptor
 * 
 * libsodium wrapper with no framework dependencies.
 * Used by both the Encryption Trongate module and Runtime worker.
 */
class Encryptor
{
    public static function validate_key(string $key): string
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
    public static function encrypt(string $plaintext, string $key): string
    {
        $key = self::validate_key($key);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt a string
     * @param string $encrypted Base64 encoded (nonce + ciphertext)
     * @param string $key
     * @return string|false Plaintext or false on failure
     */
    public static function decrypt(string $encrypted, string $key): string|false
    {
        $decoded = base64_decode($encrypted);
        if ($decoded === false) {
            return false;
        }

        if (strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }

        $key = self::validate_key($key);
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
    }

    /**
     * Generate a new encryption key
     * @return string Base64 encoded key
     */
    public static function generate_key(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }
}
