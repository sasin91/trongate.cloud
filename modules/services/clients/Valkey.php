<?php

/**
 * Minimal Valkey (RESP2) client for the worker's admin commands: managing the
 * per-app ACL users that customer apps keep their PHP sessions under
 * (Manifests::sessions_ini). Speaks the protocol over a plain socket, so the
 * worker image needs no redis extension.
 *
 * Framework-free: loaded by the worker.
 */
class Valkey {

    /** @var resource|null */
    private $socket = null;

    function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $password,
        private int $timeout_seconds = 5,
    ) {
    }

    /**
     * The worker's admin connection, from VALKEY_HOST / VALKEY_PORT /
     * VALKEY_WORKER_USER / VALKEY_WORKER_PASSWORD (config/platform.php)
     */
    static function from_env(): self {
        return new self(VALKEY_HOST, VALKEY_PORT, VALKEY_WORKER_USER, VALKEY_WORKER_PASSWORD);
    }

    /**
     * Whether the worker has Valkey credentials. Without them customer apps
     * keep PHP's default file sessions.
     */
    static function configured(): bool {
        return VALKEY_WORKER_PASSWORD !== '';
    }

    /**
     * Run one command and return its reply (string, int, null or list)
     *
     * @throws RuntimeException on connection, protocol or server errors
     */
    function command(string ...$args): mixed {
        $this->connect();
        $this->write($args);

        return $this->read();
    }

    function close(): void {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    function __destruct() {
        $this->close();
    }

    private function connect(): void {
        if (is_resource($this->socket)) {
            return;
        }

        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout_seconds);

        if ($socket === false) {
            throw new RuntimeException("Valkey {$this->host}:{$this->port} unreachable: {$error} ({$errno})");
        }

        stream_set_timeout($socket, $this->timeout_seconds);
        $this->socket = $socket;

        // Never put the password in an exception message
        $this->write(['AUTH', $this->user, $this->password]);

        try {
            $this->read();
        } catch (RuntimeException $e) {
            $this->close();
            throw new RuntimeException("Valkey AUTH as {$this->user} failed: " . preg_replace('/\s+/', ' ', $e->getMessage()));
        }
    }

    /**
     * @param list<string> $args
     */
    private function write(array $args): void {
        $payload = '*' . count($args) . "\r\n";

        foreach ($args as $arg) {
            $payload .= '$' . strlen($arg) . "\r\n{$arg}\r\n";
        }

        for ($written = 0; $written < strlen($payload); $written += $n) {
            $n = fwrite($this->socket, substr($payload, $written));

            if ($n === false || $n === 0) {
                $this->close();
                throw new RuntimeException('Valkey write failed');
            }
        }
    }

    private function read(): mixed {
        $line = fgets($this->socket);

        if ($line === false) {
            $this->close();
            throw new RuntimeException('Valkey connection closed or timed out');
        }

        $type = $line[0];
        $body = substr($line, 1, -2);

        switch ($type) {
            case '+':
                return $body;
            case '-':
                throw new RuntimeException($body);
            case ':':
                return (int) $body;
            case '$':
                $length = (int) $body;

                if ($length < 0) {
                    return null;
                }

                $data = '';

                while (strlen($data) < $length + 2) {
                    $chunk = fread($this->socket, $length + 2 - strlen($data));

                    if ($chunk === false || $chunk === '') {
                        $this->close();
                        throw new RuntimeException('Valkey connection closed mid-reply');
                    }

                    $data .= $chunk;
                }

                return substr($data, 0, $length);
            case '*':
                $count = (int) $body;

                if ($count < 0) {
                    return null;
                }

                $items = [];

                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->read();
                }

                return $items;
            default:
                $this->close();
                throw new RuntimeException('Unexpected Valkey reply: ' . substr($line, 0, 40));
        }
    }
}
