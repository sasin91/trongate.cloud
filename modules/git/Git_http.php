<?php

require_once APPPATH . 'modules/platform/git/Git_repo.php';

/**
 * Git's smart HTTP protocol for one repository: runs `git http-backend` as a
 * CGI program, streams the request body into it and its response back out.
 *
 * Clone and fetch (git-upload-pack) and push (git-receive-pack) both go
 * through here; Git (the controller) has checked the token first.
 */
final class Git_http {

    /** A push larger than this is refused: generated and hand-written Trongate apps are a few MB */
    const MAX_REQUEST_BYTES = 268435456;

    const READ_BYTES = 65536;

    /**
     * Serve one request for $repo. $service_path is what follows the
     * repository in the URL: info/refs, git-upload-pack or git-receive-pack.
     */
    static function serve(Git_repo $repo, string $service_path, string $remote_user): void {
        $environment = self::cgi_environment($repo, $service_path, $remote_user);
        // git's messages go to a file, so a full stderr pipe can never stall it
        // while the request body is still being written
        $error_file = (string) tempnam(sys_get_temp_dir(), 'git-http-');
        $pipes = [];
        $process = proc_open(['git', '-c', 'safe.directory=*', 'http-backend'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $error_file, 'w']], $pipes, null, $environment);

        if (!is_resource($process)) {
            @unlink($error_file);
            self::refuse(500, 'git could not be started');
            return;
        }

        $copied = self::copy_request_body($pipes[0]);
        fclose($pipes[0]);

        if ($copied === false) {
            proc_terminate($process);
            proc_close($process);
            @unlink($error_file);
            self::refuse(413, 'This push is too large.');
            return;
        }

        self::send_response($pipes[1]);
        fclose($pipes[1]);

        $code = proc_close($process);
        $errors = trim((string) file_get_contents($error_file, false, null, 0, 4096));
        @unlink($error_file);

        if ($code !== 0 && $errors !== '') {
            error_log("[git] git http-backend for {$repo->name} exited {$code}: {$errors}");
        }
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
     * Copy the request body into git's stdin, at most MAX_REQUEST_BYTES.
     * Without a Content-Length (a chunked push) git reads until the end.
     *
     * @param resource $stdin
     */
    static function copy_request_body($stdin): bool {
        $input = fopen('php://input', 'rb');

        if ($input === false) {
            return true;
        }

        $total = 0;

        while (!feof($input)) {
            $chunk = fread($input, self::READ_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $total += strlen($chunk);

            if ($total > self::MAX_REQUEST_BYTES) {
                fclose($input);
                return false;
            }

            fwrite($stdin, $chunk);
        }

        fclose($input);

        return true;
    }

    /**
     * Turn http-backend's CGI headers into response headers, then pass the body through
     *
     * @param resource $stdout
     */
    static function send_response($stdout): void {
        $head = '';

        while (!feof($stdout) && !str_contains($head, "\r\n\r\n") && !str_contains($head, "\n\n")) {
            $line = fgets($stdout);

            if ($line === false) {
                break;
            }

            $head .= $line;

            if ($line === "\r\n" || $line === "\n") {
                break;
            }
        }

        $parsed = self::parse_cgi_head($head);
        http_response_code($parsed['status']);

        foreach ($parsed['headers'] as $header) {
            header($header);
        }

        while (!feof($stdout)) {
            $chunk = fread($stdout, self::READ_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            flush();
        }
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
