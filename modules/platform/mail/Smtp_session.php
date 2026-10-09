<?php

/**
 * One SMTP conversation with the inbox (Mail_server), without the socket:
 * feed it the client's lines, send back what it returns. Enough of SMTP for
 * Trongate's email module and the usual PHP mailers: EHLO/HELO, AUTH LOGIN
 * and PLAIN, MAIL, RCPT, DATA, RSET, NOOP, QUIT. No STARTTLS: the inbox is
 * only reachable inside the cluster.
 *
 * Mail is only accepted after AUTH; $authenticate turns the username and
 * password into the app's id (or null), and every message of the session
 * belongs to that app. $deliver stores a message and says whether it worked.
 *
 * Framework-free.
 */
final class Smtp_session {

    /** Recipients per message (RFC 5321 asks servers to take at least 100) */
    const MAX_RECIPIENTS = 100;

    /** Failed AUTH attempts before the session is closed */
    const MAX_AUTH_FAILURES = 3;

    /** Longest command line (RFC 5321: 512 octets including CRLF) we accept, generously */
    const MAX_COMMAND_LENGTH = 2048;

    /** Set once the session should be closed after sending the last reply */
    public bool $closed = false;

    private ?int $app_id = null;
    private ?string $mail_from = null;

    /** @var list<string> */
    private array $recipients = [];

    /** What the next line is: a command, an AUTH step or message data */
    private string $state = 'command';

    private ?string $auth_user = null;
    private int $auth_failures = 0;
    private string $data = '';
    private bool $data_too_big = false;

    /**
     * @param Closure(string, string): ?int $authenticate username, password => app id
     * @param Closure(int, string, list<string>, string): bool $deliver app id, MAIL FROM, RCPT TOs, message
     */
    function __construct(
        private Closure $authenticate,
        private Closure $deliver,
        private string $hostname = 'mail.trongate.cloud',
        private int $max_bytes = 2 * 1024 * 1024,
    ) {
    }

    /** The app that signed in, or null before AUTH */
    function app_id(): ?int {
        return $this->app_id;
    }

    function greeting(): string {
        return "220 {$this->hostname} ESMTP trongate.cloud mail capture\r\n";
    }

    /**
     * Handle one line from the client (without its line ending); the reply
     * to send, or '' while a message's data is still coming
     */
    function line(string $line): string {
        if ($this->state === 'data') {
            return $this->data_line($line);
        }

        if (strlen($line) > self::MAX_COMMAND_LENGTH) {
            $this->state = 'command';

            return "500 5.5.2 Line too long\r\n";
        }

        if ($this->state !== 'command') {
            return $this->auth_line($line);
        }

        $verb = strtoupper(strtok($line, ' ') ?: '');
        $argument = trim(substr($line, strlen($verb)));

        return match ($verb) {
            'EHLO' => $this->ehlo($argument),
            'HELO' => $this->reset() . "250 {$this->hostname}\r\n",
            'AUTH' => $this->auth($argument),
            'MAIL' => $this->mail($argument),
            'RCPT' => $this->rcpt($argument),
            'DATA' => $this->data(),
            'RSET' => $this->reset() . "250 2.0.0 OK\r\n",
            'NOOP' => "250 2.0.0 OK\r\n",
            'VRFY' => "252 2.1.5 Cannot verify, send some mail\r\n",
            'STARTTLS' => "454 4.7.0 TLS not available, the connection stays inside the cluster\r\n",
            'QUIT' => $this->quit(),
            '' => "500 5.5.2 Empty command\r\n",
            default => "502 5.5.1 Command not implemented\r\n",
        };
    }

    private function ehlo(string $argument): string {
        $this->reset();

        if ($argument === '') {
            return "501 5.5.4 EHLO needs a hostname\r\n";
        }

        return "250-{$this->hostname}\r\n"
            . "250-SIZE {$this->max_bytes}\r\n"
            . "250-8BITMIME\r\n"
            . "250-ENHANCEDSTATUSCODES\r\n"
            . "250 AUTH LOGIN PLAIN\r\n";
    }

    private function auth(string $argument): string {
        if ($this->app_id !== null) {
            return "503 5.5.1 Already authenticated\r\n";
        }

        [$mechanism, $initial] = array_pad(explode(' ', $argument, 2), 2, null);
        $mechanism = strtoupper((string) $mechanism);

        if ($mechanism === 'LOGIN') {
            if ($initial === null) {
                $this->state = 'auth_login_user';

                return '334 ' . base64_encode('Username:') . "\r\n";
            }

            $this->state = 'auth_login_user';

            return $this->auth_line($initial);
        }

        if ($mechanism === 'PLAIN') {
            if ($initial === null) {
                $this->state = 'auth_plain';

                return "334 \r\n";
            }

            $this->state = 'auth_plain';

            return $this->auth_line($initial);
        }

        return "504 5.5.4 Use AUTH LOGIN or AUTH PLAIN\r\n";
    }

    private function auth_line(string $line): string {
        $state = $this->state;
        $this->state = 'command';

        if (trim($line) === '*') {
            return "501 5.0.0 Authentication cancelled\r\n";
        }

        $decoded = base64_decode(trim($line), true);

        if ($decoded === false) {
            return "501 5.5.2 Cannot decode the response\r\n";
        }

        if ($state === 'auth_login_user') {
            $this->auth_user = $decoded;
            $this->state = 'auth_login_password';

            return '334 ' . base64_encode('Password:') . "\r\n";
        }

        if ($state === 'auth_login_password') {
            return $this->check_credentials((string) $this->auth_user, $decoded);
        }

        // PLAIN: authorization identity NUL username NUL password
        $parts = explode("\0", $decoded);

        return count($parts) === 3
            ? $this->check_credentials($parts[1], $parts[2])
            : "501 5.5.2 Malformed AUTH PLAIN response\r\n";
    }

    private function check_credentials(string $user, string $password): string {
        $this->auth_user = null;
        $app_id = ($this->authenticate)($user, $password);

        if ($app_id !== null) {
            $this->app_id = $app_id;

            return "235 2.7.0 Authentication successful\r\n";
        }

        if (++$this->auth_failures >= self::MAX_AUTH_FAILURES) {
            $this->closed = true;

            return "421 4.7.0 Too many failed attempts, closing\r\n";
        }

        return "535 5.7.8 Authentication failed\r\n";
    }

    private function mail(string $argument): string {
        if ($this->app_id === null) {
            return "530 5.7.0 Authentication required\r\n";
        }

        if ($this->mail_from !== null) {
            return "503 5.5.1 Sender already given\r\n";
        }

        if (!preg_match('/^FROM:\s*<([^>]*)>(.*)$/i', $argument, $match)) {
            return "501 5.5.4 Syntax: MAIL FROM:<address>\r\n";
        }

        if (preg_match('/\bSIZE=(\d+)/i', $match[2], $size) && (int) $size[1] > $this->max_bytes) {
            return "552 5.3.4 Message larger than {$this->max_bytes} bytes\r\n";
        }

        $this->mail_from = $match[1];
        $this->recipients = [];

        return "250 2.1.0 OK\r\n";
    }

    private function rcpt(string $argument): string {
        if ($this->mail_from === null) {
            return "503 5.5.1 MAIL first\r\n";
        }

        if (!preg_match('/^TO:\s*<([^>]+)>/i', $argument, $match)) {
            return "501 5.5.4 Syntax: RCPT TO:<address>\r\n";
        }

        if (count($this->recipients) >= self::MAX_RECIPIENTS) {
            return '452 4.5.3 At most ' . self::MAX_RECIPIENTS . " recipients per message\r\n";
        }

        $this->recipients[] = $match[1];

        return "250 2.1.5 OK\r\n";
    }

    private function data(): string {
        if ($this->recipients === []) {
            return "503 5.5.1 RCPT first\r\n";
        }

        $this->state = 'data';
        $this->data = '';
        $this->data_too_big = false;

        return "354 End data with <CR><LF>.<CR><LF>\r\n";
    }

    private function data_line(string $line): string {
        if ($line === '.') {
            $this->state = 'command';
            $reply = $this->finish_data();
            $this->mail_from = null;
            $this->recipients = [];
            $this->data = '';

            return $reply;
        }

        if ($this->data_too_big) {
            return '';
        }

        // Dot-stuffing (RFC 5321 4.5.2)
        $this->data .= (str_starts_with($line, '.') ? substr($line, 1) : $line) . "\r\n";

        if (strlen($this->data) > $this->max_bytes) {
            $this->data_too_big = true;
            $this->data = '';
        }

        return '';
    }

    private function finish_data(): string {
        if ($this->data_too_big) {
            return "552 5.3.4 Message larger than {$this->max_bytes} bytes\r\n";
        }

        try {
            $stored = ($this->deliver)((int) $this->app_id, (string) $this->mail_from, $this->recipients, $this->data);
        } catch (Throwable $e) {
            error_log('Storing a message failed: ' . $e->getMessage());
            $stored = false;
        }

        return $stored
            ? "250 2.0.0 OK: captured, not delivered\r\n"
            : "451 4.3.0 Could not store the message, try again\r\n";
    }

    private function quit(): string {
        $this->closed = true;

        return "221 2.0.0 Bye\r\n";
    }

    /** Forget the message in progress (not the authentication) */
    private function reset(): string {
        $this->mail_from = null;
        $this->recipients = [];
        $this->data = '';
        $this->state = 'command';

        return '';
    }
}
