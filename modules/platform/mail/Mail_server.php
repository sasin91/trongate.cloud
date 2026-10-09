<?php

require_once __DIR__ . '/Smtp_session.php';

/**
 * The inbox's socket loop: accepts SMTP connections and runs an
 * Smtp_session for each, all in one process (stream_select). The traffic
 * is a few messages from apps inside the cluster, so one process is plenty.
 *
 * Framework-free.
 */
final class Mail_server {

    /**
     * Open connections, and per app once signed in; more are told to come
     * back later. Each may buffer a message of up to MAX_MESSAGE_BYTES, so
     * this also bounds memory (30 x 2 MB, under the pod's 128 Mi).
     */
    const MAX_CLIENTS = 30;
    const MAX_CLIENTS_PER_APP = 3;

    /** A connection silent this long is closed */
    const IDLE_SECONDS = 120;

    /** Longest line we buffer; message lines are at most 1000 octets (RFC 5321) */
    const MAX_LINE_BYTES = 64 * 1024;

    public bool $running = true;

    /** @var array<int, array{socket: resource, session: Smtp_session, buffer: string, seen: int}> */
    private array $clients = [];

    /**
     * @param resource $listener from stream_socket_server()
     * @param Closure(): Smtp_session $new_session
     * @param ?Closure(): void $tick called about once a second (housekeeping)
     */
    function __construct(private $listener, private Closure $new_session, private ?Closure $tick = null) {
        stream_set_blocking($this->listener, false);
    }

    function run(): void {
        $next_tick = time();

        while ($this->running) {
            $read = [$this->listener, ...array_column($this->clients, 'socket')];
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                // Interrupted by a signal (SIGTERM sets $running)
                continue;
            }

            foreach ($read as $socket) {
                $socket === $this->listener ? $this->accept() : $this->receive($socket);
            }

            if (time() >= $next_tick) {
                $next_tick = time() + 1;
                $this->close_idle();

                if ($this->tick !== null) {
                    ($this->tick)();
                }
            }
        }

        foreach ($this->clients as $client) {
            $this->send($client['socket'], "421 4.3.2 Shutting down, try again\r\n");
            $this->close($client['socket']);
        }
    }

    private function accept(): void {
        $socket = @stream_socket_accept($this->listener, 0);

        if ($socket === false) {
            return;
        }

        if (count($this->clients) >= self::MAX_CLIENTS) {
            $this->send($socket, "421 4.3.2 Too many connections, try again\r\n");
            fclose($socket);

            return;
        }

        stream_set_blocking($socket, false);
        $session = ($this->new_session)();
        $this->clients[(int) $socket] = ['socket' => $socket, 'session' => $session, 'buffer' => '', 'seen' => time()];
        $this->send($socket, $session->greeting());
    }

    /**
     * @param resource $socket
     */
    private function receive($socket): void {
        $id = (int) $socket;
        $chunk = fread($socket, 65536);

        if ($chunk === false || ($chunk === '' && feof($socket))) {
            $this->close($socket);

            return;
        }

        $client = &$this->clients[$id];
        $client['buffer'] .= $chunk;
        $client['seen'] = time();

        while (($end = strpos($client['buffer'], "\n")) !== false) {
            $line = rtrim(substr($client['buffer'], 0, $end), "\r");
            $client['buffer'] = (string) substr($client['buffer'], $end + 1);
            $reply = $client['session']->line($line);

            // Checked when AUTH succeeds, instead of its 235
            if ($this->over_app_limit($id)) {
                $this->send($socket, "421 4.7.0 Too many connections from this app, try again\r\n");
                $this->close($socket);

                return;
            }

            if ($reply !== '') {
                $this->send($socket, $reply);
            }

            if ($client['session']->closed) {
                $this->close($socket);

                return;
            }
        }

        if (strlen($client['buffer']) > self::MAX_LINE_BYTES) {
            $this->send($socket, "500 5.5.2 Line too long, closing\r\n");
            $this->close($socket);
        }
    }

    /** Whether the client's app has more than MAX_CLIENTS_PER_APP connections */
    private function over_app_limit(int $id): bool {
        $app_id = $this->clients[$id]['session']->app_id();

        if ($app_id === null) {
            return false;
        }

        $same = array_filter($this->clients, fn(array $client) => $client['session']->app_id() === $app_id);

        return count($same) > self::MAX_CLIENTS_PER_APP;
    }

    private function close_idle(): void {
        foreach ($this->clients as $client) {
            if (time() - $client['seen'] > self::IDLE_SECONDS) {
                $this->send($client['socket'], "421 4.4.2 Idle too long, closing\r\n");
                $this->close($client['socket']);
            }
        }
    }

    /**
     * @param resource $socket
     */
    private function send($socket, string $reply): void {
        // Replies are short; a client that does not read them is closed
        stream_set_blocking($socket, true);
        stream_set_timeout($socket, 5);
        @fwrite($socket, $reply);
        stream_set_blocking($socket, false);
    }

    /**
     * @param resource $socket
     */
    private function close($socket): void {
        unset($this->clients[(int) $socket]);
        @fclose($socket);
    }
}
