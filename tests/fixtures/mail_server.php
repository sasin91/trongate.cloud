<?php

/**
 * The inbox's socket loop with a file instead of the database, for
 * tests/Mail_capture_test.php: php tests/fixtures/mail_server.php {out.jsonl}
 * Prints the port it listens on (127.0.0.1, picked by the OS), then serves
 * until killed. Each stored message is one JSON line in {out.jsonl}.
 */

require __DIR__ . '/../../modules/platform/mail/Mail_capture.php';
require __DIR__ . '/../../modules/platform/mail/Mail_server.php';

$out = $argv[1];
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr) ?: exit(1);
echo parse_url('tcp://' . stream_socket_get_name($listener, false), PHP_URL_PORT) . "\n";

$server = new Mail_server($listener, fn() => new Smtp_session(
    fn(string $user, string $password) => Mail_capture::app_id($password),
    function (int $app_id, string $from, array $recipients, string $raw) use ($out) {
        file_put_contents($out, json_encode(compact('app_id', 'from', 'recipients', 'raw')) . "\n", FILE_APPEND);

        return true;
    },
    'mail.test',
    Mail_capture::MAX_MESSAGE_BYTES,
));
$server->run();
