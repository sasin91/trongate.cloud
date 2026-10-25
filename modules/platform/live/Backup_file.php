<?php

/**
 * Encrypted backup files (Daily_backup): libsodium's secretstream
 * (XChaCha20-Poly1305) under a fresh key per file, that key sealed to the
 * platform's public key (APP_BACKUPS_PUBLIC_KEY) and kept in the file's
 * header. Encrypting needs only the public key, so a backup Job in a tenant
 * namespace can encrypt but nothing there can read a backup; reading needs
 * the private key (APP_BACKUPS_PRIVATE_KEY), which only the worker and the
 * offline copy hold. A file can be read with nothing but that key: not the
 * platform database, not the bucket's records.
 *
 * Layout:
 *   MAGIC
 *   sealed file key (SEALED_KEY_BYTES)
 *   secretstream header (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES)
 *   chunks: 4-byte big-endian length, then that many bytes of ciphertext;
 *           the last one carries the FINAL tag, so a cut-off file is refused
 *
 * Keys are base64 in the environment: the 32-byte private key and the
 * 32-byte public key made from it (bin/backup-keys.php).
 *
 * Used by the backup scripts of the app base image (docker/app-base, which
 * copies this file into /usr/local/lib/trongate-cloud) and by the worker.
 * Framework-free.
 */
final class Backup_file {

    const MAGIC = "TCBACKUP1\n";

    /** Plaintext bytes per chunk */
    const CHUNK_BYTES = 1024 * 1024;

    /** sodium_crypto_box_seal of a 32-byte key */
    const SEALED_KEY_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES + SODIUM_CRYPTO_BOX_SEALBYTES;

    /** Bytes before the first chunk: all that open_key() needs */
    const HEADER_BYTES = 10 + self::SEALED_KEY_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

    /**
     * A new key pair, base64
     *
     * @return array{public: string, private: string}
     */
    static function new_keys(): array {
        $pair = sodium_crypto_box_keypair();

        return [
            'public' => base64_encode(sodium_crypto_box_publickey($pair)),
            'private' => base64_encode(sodium_crypto_box_secretkey($pair)),
        ];
    }

    /** The base64 public key that belongs to a base64 private key */
    static function public_key_of(string $private_key): string {
        return base64_encode(sodium_crypto_box_publickey_from_secretkey(self::decode_key($private_key, 'private')));
    }

    /**
     * Encrypt the file at $in_path into $out_path
     *
     * @param string $public_key base64
     * @throws RuntimeException when a file cannot be read or written
     */
    static function encrypt(string $in_path, string $out_path, string $public_key): void {
        $public = self::decode_key($public_key, 'public');
        $file_key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $stream_header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($file_key);

        $in = self::open($in_path, 'rb');
        $out = self::open($out_path, 'wb');

        self::write($out, self::MAGIC . sodium_crypto_box_seal($file_key, $public) . $stream_header);
        sodium_memzero($file_key);

        $chunk = self::read_chunk($in);

        while (true) {
            $next = feof($in) ? '' : self::read_chunk($in);
            $last = $next === '' && feof($in);
            $tag = $last
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);

            self::write($out, pack('N', strlen($cipher)) . $cipher);

            if ($last) {
                break;
            }

            $chunk = $next;
        }

        fclose($in);

        if (!fclose($out)) {
            throw new RuntimeException("Cannot finish writing {$out_path}");
        }
    }

    /**
     * The file key of an encrypted file, from its first HEADER_BYTES; what a
     * restore Job is given instead of the private key, so it can read that
     * one file and no other
     *
     * @param string $private_key base64
     * @return string the 32-byte file key
     * @throws RuntimeException when it is not a backup file or not for this key
     */
    static function open_key(string $header, string $private_key): string {
        if (strlen($header) < self::HEADER_BYTES || !str_starts_with($header, self::MAGIC)) {
            throw new RuntimeException('This is not an encrypted trongate.cloud backup');
        }

        $secret = self::decode_key($private_key, 'private');
        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, sodium_crypto_box_publickey_from_secretkey($secret));
        $file_key = sodium_crypto_box_seal_open(substr($header, strlen(self::MAGIC), self::SEALED_KEY_BYTES), $pair);

        if ($file_key === false) {
            throw new RuntimeException('This backup was encrypted for another key');
        }

        return $file_key;
    }

    /**
     * Decrypt the file at $in_path into $out_path with its file key (open_key())
     *
     * @throws RuntimeException when the file was changed, cut off, or the key is wrong
     */
    static function decrypt_with_file_key(string $in_path, string $out_path, string $file_key): void {
        $in = self::open($in_path, 'rb');
        $header = (string) fread($in, self::HEADER_BYTES);

        if (strlen($header) !== self::HEADER_BYTES || !str_starts_with($header, self::MAGIC)) {
            throw new RuntimeException('This is not an encrypted trongate.cloud backup');
        }

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, -SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $file_key);
        $out = self::open($out_path, 'wb');
        $finished = false;

        while (!$finished) {
            $length = fread($in, 4);

            if ($length === false || strlen($length) !== 4) {
                throw new RuntimeException('The backup is cut off');
            }

            $size = unpack('N', $length)[1];

            if ($size > self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                throw new RuntimeException('The backup is damaged');
            }

            $cipher = $size === 0 ? '' : (string) fread($in, $size);

            if (strlen($cipher) !== $size) {
                throw new RuntimeException('The backup is cut off');
            }

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);

            if ($result === false) {
                throw new RuntimeException('The backup is damaged or was changed');
            }

            [$plain, $tag] = $result;
            self::write($out, $plain);
            $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }

        if (fread($in, 1) !== '') {
            throw new RuntimeException('The backup has data after its end');
        }

        fclose($in);

        if (!fclose($out)) {
            throw new RuntimeException("Cannot finish writing {$out_path}");
        }
    }

    /**
     * Decrypt the file at $in_path into $out_path with the private key
     *
     * @param string $private_key base64
     */
    static function decrypt(string $in_path, string $out_path, string $private_key): void {
        $in = self::open($in_path, 'rb');
        $header = (string) fread($in, self::HEADER_BYTES);
        fclose($in);

        $file_key = self::open_key($header, $private_key);
        self::decrypt_with_file_key($in_path, $out_path, $file_key);
        sodium_memzero($file_key);
    }

    static function decode_key(string $base64, string $kind): string {
        $key = base64_decode(trim($base64), true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new RuntimeException("The backups {$kind} key is not a base64 32-byte key (bin/backup-keys.php makes one)");
        }

        return $key;
    }

    /** @return resource */
    static function open(string $path, string $mode) {
        $handle = fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        return $handle;
    }

    /** Up to CHUNK_BYTES from $in, fewer only at its end */
    static function read_chunk($in): string {
        $chunk = '';

        while (strlen($chunk) < self::CHUNK_BYTES && !feof($in)) {
            $part = fread($in, self::CHUNK_BYTES - strlen($chunk));

            if ($part === false) {
                throw new RuntimeException('Cannot read the file to encrypt');
            }

            $chunk .= $part;
        }

        return $chunk;
    }

    static function write($out, string $bytes): void {
        if (fwrite($out, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot write the backup file (is the disk full?)');
        }
    }
}
