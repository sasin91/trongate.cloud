<?php

/**
 * Tests for mail capture: the per-app passwords (Mail_capture), the SMTP
 * conversation (Smtp_session), reading messages (Mail_message), and Trongate's
 * own email module sending through the inbox's socket loop (Mail_server).
 * The config rules are in Config_editor_test.php.
 *
 * Run: php tests/Mail_capture_test.php (exits non-zero on a failure)
 */

putenv('APP_KEY=test-key-for-mail-capture');

require_once __DIR__ . '/../modules/platform/mail/Mail_capture.php';
require_once __DIR__ . '/../modules/platform/mail/Mail_message.php';
require_once __DIR__ . '/../modules/platform/mail/Smtp_session.php';

$failures = 0;

$test = function (string $name, callable $body) use (&$failures): void {
    try {
        $body();
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}\n     {$e->getMessage()}\n";
    }
};

$same = function ($expected, $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Expected:\n" . var_export($expected, true) . "\nGot:\n" . var_export($actual, true));
    }
};

/**
 * A session for app 7 (password "good") that keeps what it delivers; send
 * lines and get the reply codes back
 */
$session = function (?array &$delivered = null, int $max_bytes = 1000): Smtp_session {
    $delivered = [];

    return new Smtp_session(
        fn(string $user, string $password) => $password === 'good' ? 7 : null,
        function (int $app_id, string $from, array $recipients, string $raw) use (&$delivered) {
            $delivered[] = compact('app_id', 'from', 'recipients', 'raw');

            return true;
        },
        'mail.test',
        $max_bytes,
    );
};

$codes = fn(Smtp_session $session, array $lines): array => array_map(fn(string $line) => substr($session->line($line), 0, 3), $lines);

$test('passwords carry the app id and only check out with the key', function () use ($same) {
    $password = Mail_capture::password(42);

    $same(42, Mail_capture::app_id($password));
    $same(null, Mail_capture::app_id(str_replace('tc42.', 'tc43.', $password)));
    $same(null, Mail_capture::app_id(substr($password, 0, -1) . (str_ends_with($password, '0') ? '1' : '0')));
    $same(null, Mail_capture::app_id('tc0.' . str_repeat('a', Mail_capture::PASSWORD_HASH_LENGTH)));
    $same(null, Mail_capture::app_id(''));
});

$test('capture is on unless the app turned it off', function () use ($same) {
    $same([true, true, false], [Mail_capture::enabled([]), Mail_capture::enabled(['mail_capture' => '1']), Mail_capture::enabled(['mail_capture' => 0])]);
});

$test('mail needs AUTH first', function () use ($session, $codes, $same) {
    $same(['250', '530'], $codes($session(), ['EHLO app', 'MAIL FROM:<a@b.c>']));
});

$test('AUTH LOGIN, then a message with dot-stuffing', function () use ($session, $codes, $same) {
    $smtp = $session($delivered);
    $same(
        ['250', '334', '334', '235', '250', '250', '250', '354', '', '', '', '', '250', '221'],
        $codes($smtp, ['EHLO app', 'AUTH LOGIN', base64_encode('me@shop.test'), base64_encode('good'), 'MAIL FROM:<me@shop.test> SIZE=100', 'RCPT TO:<a@x.test>', 'RCPT TO:<b@x.test>', 'DATA', 'Subject: Hi', '', '..leading dot', 'body', '.', 'QUIT']),
    );
    $same([['app_id' => 7, 'from' => 'me@shop.test', 'recipients' => ['a@x.test', 'b@x.test'], 'raw' => "Subject: Hi\r\n\r\n.leading dot\r\nbody\r\n"]], $delivered);
    $same(true, $smtp->closed);
});

$test('AUTH PLAIN, inline and on the next line', function () use ($session, $codes, $same) {
    $same(['235'], $codes($session(), ['AUTH PLAIN ' . base64_encode("\0me\0good")]));
    $same(['334', '235'], $codes($session(), ['AUTH PLAIN', base64_encode("\0me\0good")]));
    $same(['535'], $codes($session(), ['AUTH PLAIN ' . base64_encode("\0me\0bad")]));
});

$test('three wrong passwords close the session', function () use ($session, $codes, $same) {
    $smtp = $session();
    $same(['535', '535', '421'], $codes($smtp, array_fill(0, 3, 'AUTH PLAIN ' . base64_encode("\0me\0bad"))));
    $same(true, $smtp->closed);
});

$test('a message over the size limit is refused, and the session goes on', function () use ($session, $codes, $same) {
    $smtp = $session($delivered, 100);
    $same(['235', '552'], $codes($smtp, ['AUTH PLAIN ' . base64_encode("\0me\0good"), 'MAIL FROM:<a@b.c> SIZE=101']));
    $same(['250', '250', '354', '', '', '552', '250'], $codes($smtp, ['MAIL FROM:<a@b.c>', 'RCPT TO:<x@y.z>', 'DATA', str_repeat('x', 60), str_repeat('x', 60), '.', 'NOOP']));
    $same([], $delivered);
});

$test('order and unknown commands', function () use ($session, $codes, $same) {
    $same(['503', '503', '502', '454'], $codes($session(), ['RCPT TO:<a@b.c>', 'DATA', 'TURN', 'STARTTLS']));
});

$test('Mail_message reads a multipart message with encoded headers', function () use ($same) {
    $raw = implode("\r\n", [
        'From: =?UTF-8?B?' . base64_encode('Søren Shop') . '?= <shop@example.test>',
        'To: buyer@example.test',
        'Subject: =?UTF-8?B?' . base64_encode('Ordre bekræftet') . '?=',
        'Content-Type: multipart/mixed; boundary="outer"',
        '',
        '--outer',
        'Content-Type: multipart/alternative; boundary=inner',
        '',
        '--inner',
        'Content-Type: text/plain; charset=ISO-8859-1',
        'Content-Transfer-Encoding: quoted-printable',
        '',
        'Tak for din ordre, s=F8de',
        '--inner',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('<p>Tak</p>'),
        '--inner--',
        '--outer',
        'Content-Type: application/pdf; name="invoice.pdf"',
        'Content-Disposition: attachment; filename="invoice.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        'JVBERi0=',
        '--outer--',
        '',
    ]);
    $message = Mail_message::parse($raw);

    $same('Søren Shop <shop@example.test>', $message['from']);
    $same('Ordre bekræftet', $message['subject']);
    $same('Tak for din ordre, søde', $message['text']);
    $same('<p>Tak</p>', $message['html']);
    $same(['invoice.pdf'], $message['attachments']);
});

$test("Trongate's email module sends through the inbox", function () use ($same) {
    $dir = sys_get_temp_dir() . '/mail-capture-' . getmypid();
    @mkdir("{$dir}/config", 0777, true);
    $out = "{$dir}/captured.jsonl";

    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/fixtures/mail_server.php', $out],
        [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['APP_KEY' => getenv('APP_KEY')],
    );
    $port = (int) fgets($pipes[1]);

    try {
        // What Mail_capture::rules() sets, in the shape of the stock config file
        $config = [
            'smtp_host' => '127.0.0.1',
            'smtp_port' => $port,
            'smtp_user' => 'hello@shop.test',
            'smtp_pass' => Mail_capture::password(7),
            'smtp_secure' => '',
            'smtp_from_name' => 'Shop',
        ];
        file_put_contents("{$dir}/config/trongate_email.php", "<?php\n\$config['trongate_email'] = " . var_export($config, true) . ";\n");

        defined('APPPATH') || define('APPPATH', "{$dir}/");

        if (!function_exists('block_url')) {
            function block_url(string $module): void {
            }
        }

        require_once __DIR__ . '/../modules/trongate_email/Trongate_email.php';

        $sent = (new Trongate_email())->send([
            'to_email' => 'buyer@example.test',
            'subject' => 'Your order',
            'body_html' => "<p>Thanks!</p>\n.<p>Line starting with a dot</p>",
        ]);
        $same(true, $sent);

        $config['smtp_pass'] = 'tc7.' . str_repeat('0', Mail_capture::PASSWORD_HASH_LENGTH);
        file_put_contents("{$dir}/config/trongate_email.php", "<?php\n\$config['trongate_email'] = " . var_export($config, true) . ";\n");
        $same(false, (new Trongate_email())->send(['to_email' => 'x@example.test', 'subject' => 'x', 'body_html' => 'x']));

        $captured = array_map(fn(string $line) => json_decode($line, true), file($out, FILE_IGNORE_NEW_LINES) ?: []);
        $same(1, count($captured));
        $same([7, 'hello@shop.test', ['buyer@example.test']], [$captured[0]['app_id'], $captured[0]['from'], $captured[0]['recipients']]);

        $message = Mail_message::parse($captured[0]['raw']);
        $same(['Shop <hello@shop.test>', 'buyer@example.test', 'Your order'], [$message['from'], $message['to'], $message['subject']]);
        $same("<p>Thanks!</p>\n.<p>Line starting with a dot</p>", $message['html']);
    } finally {
        proc_terminate($process);
        proc_close($process);
        @unlink($out);
        @unlink("{$dir}/config/trongate_email.php");
        @rmdir("{$dir}/config");
        @rmdir($dir);
    }
});

exit($failures === 0 ? 0 : 1);
