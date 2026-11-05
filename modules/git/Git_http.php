<?php

require_once APPPATH . 'modules/platform/git/Git_repo.php';
require_once APPPATH . 'modules/cli/Process.php';

/**
 * Git's smart HTTP protocol for one repository: runs `git http-backend` as a
 * CGI program (Process), feeds it the request body and passes its response
 * on as it comes.
 *
 * Clone and fetch (git-upload-pack) and push (git-receive-pack) both go
 * through here; Git (the controller) has checked the token first.
 */
final class Git_http {

    /** A push larger than this is refused: generated and hand-written Trongate apps are a few MB */
    const MAX_REQUEST_BYTES = 104857600;

    /** A clone of a big repository, or a push over a slow line */
    const TIMEOUT_SECONDS = 600;

    /** http-backend's CGI head, until the blank line that ends it */
    private string $head = '';

    private bool $head_sent = false;

    /**
     * Serve one request for $repo. $service_path is what follows the
     * repository in the URL: info/refs, git-upload-pack or git-receive-pack.
     */
    static function serve(Git_repo $repo, string $service_path, string $remote_user): void {
        $body = self::request_body();

        if ($body === null) {
            self::refuse(413, 'This push is too large.');
            return;
        }

        $environment = self::cgi_environment($repo, $service_path, $remote_user);
        $process = new Process(['git', '-c', 'safe.directory=*', 'http-backend'], env: $environment, timeout: self::TIMEOUT_SECONDS, input: $body);
        $response = new self();

        try {
            $code = $process->stream($response->pass_on(...));
        } catch (RuntimeException $e) {
            self::refuse(500, 'git could not be started');
            return;
        }

        $response->finish();

        if ($code !== 0) {
            error_log("[git] git http-backend for {$repo->name} exited {$code}: " . trim($process->error_output()));
        }
    }

    /**
     * The request body in a temporary stream, or null when it is larger than
     * MAX_REQUEST_BYTES. Without a Content-Length (a chunked push) it is read
     * to its end.
     *
     * @return resource|null
     */
    static function request_body() {
        $input = fopen('php://input', 'rb');
        $body = fopen('php://temp', 'w+b');

        if ($input === false || $body === false) {
            return null;
        }

        $copied = stream_copy_to_stream($input, $body, self::MAX_REQUEST_BYTES + 1);
        fclose($input);

        if ($copied === false || $copied > self::MAX_REQUEST_BYTES) {
            fclose($body);
            return null;
        }

        rewind($body);

        return $body;
    }

    /**
     * The CGI variables http-backend reads. REMOTE_USER turns receive-pack on.
     */
    static function cgi_environment(Git_repo $repo, string $service_path, string $remote_user): array {
        $environment = Git_repo::environment() + [
            'GIT_PROJECT_ROOT' => $repo->root(),
            'GIT_HTTP_EXPORT_ALL' => '1',
            'GIT_HTTP_MAX_REQUEST_BUFFER' => '10M',
            'PATH_INFO' => '/' . $repo->name . '.git/' . $service_path,
            'REQUEST_METHOD' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'QUERY_STRING' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
            'CONTENT_TYPE' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
            'REMOTE_USER' => $remote_user,
            'REMOTE_ADDR' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'GIT_PROTOCOL' => (string) ($_SERVER['HTTP_GIT_PROTOCOL'] ?? ''),
        ];

        $encoding = (string) ($_SERVER['HTTP_CONTENT_ENCODING'] ?? '');

        if ($encoding !== '') {
            $environment['HTTP_CONTENT_ENCODING'] = $encoding;
        }

        return $environment;
    }

    /**
     * One piece of http-backend's output: collect the CGI head, send it as
     * the response's status and headers, then pass the body on
     */
    function pass_on(string $chunk): void {
        if ($this->head_sent) {
            echo $chunk;
            flush();
            return;
        }

        $this->head .= $chunk;
        $end = self::head_end($this->head);

        if ($end === null) {
            return;
        }

        $rest = substr($this->head, $end);
        $this->send_head(substr($this->head, 0, $end));
        $this->pass_on($rest);
    }

    /**
     * After http-backend exits: a response that never finished its head
     * still goes out
     */
    function finish(): void {
        if (!$this->head_sent) {
            $this->send_head($this->head);
        }
    }

    function send_head(string $head): void {
        $parsed = self::parse_cgi_head($head);
        http_response_code($parsed['status']);

        foreach ($parsed['headers'] as $header) {
            header($header);
        }

        $this->head = '';
        $this->head_sent = true;
    }

    /**
     * Where the body starts: just past the blank line that ends the head
     */
    static function head_end(string $output): ?int {
        foreach (["\r\n\r\n", "\n\n"] as $blank_line) {
            $at = strpos($output, $blank_line);

            if ($at !== false) {
                return $at + strlen($blank_line);
            }
        }

        return null;
    }

    /**
     * Status code and headers of a CGI response head
     *
     * @return array{status: int, headers: list<string>}
     */
    static function parse_cgi_head(string $head): array {
        $status = 200;
        $headers = [];

        foreach (preg_split('/\r?\n/', trim($head)) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode(':', $line, 2));

            if (strcasecmp($name, 'Status') === 0) {
                $status = (int) $value ?: 200;
                continue;
            }

            $headers[] = $name . ': ' . $value;
        }

        return ['status' => $status, 'headers' => $headers];
    }

    /**
     * A plain-text refusal that git shows the user
     */
    static function refuse(int $status, string $message): void {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');

        if ($status === 401) {
            header('WWW-Authenticate: Basic realm="trongate.cloud git"');
        }

        echo $message . "\n";
    }
}
