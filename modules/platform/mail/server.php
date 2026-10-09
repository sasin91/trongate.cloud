<?php

/**
 * The platform's SMTP inbox: php modules/platform/mail/server.php
 *
 * Runs from the worker image as its own small Deployment
 * (deploy/k8s/platform/mail.yaml). Apps with mail capture on send here
 * (Mail_capture::rules); each message is stored in app_mail for the app
 * whose password signed in, and never delivered. Configuration comes from
 * the environment: DB_* (its own MariaDB user, limited to app_mail),
 * MAIL_KEY and PLATFORM_MAIL_PORT. It never gets APP_KEY: tenants reach it.
 */

defined('APPPATH') || define('APPPATH', dirname(__DIR__, 3) . '/');

require APPPATH . 'modules/platform/worker/Runtime.php';
require __DIR__ . '/Mail_capture.php';
require __DIR__ . '/Mail_server.php';
require __DIR__ . '/Mail_store.php';

// Retry the first connection like the worker: the platform MariaDB's
// NetworkPolicy refuses a new pod's IP until k3s has synced it
$connect = function (): PDO {
    $max_attempts = 10;

    return retry($max_attempts, fn() => Runtime::connect(), fn(int $attempt) => min(250 * 2 ** ($attempt - 1), 5000), function (Throwable $e, int $attempt) use ($max_attempts) {
        error_log("DB connection failed (attempt {$attempt}/{$max_attempts}): {$e->getMessage()}");

        return $e instanceof PDOException;
    });
};

try {
    $store = new Mail_store($connect());
} catch (PDOException) {
    exit(1);
}

// Signs the apps' passwords (Mail_capture::password)
if (MAIL_KEY === '') {
    error_log('MAIL_KEY is required');
    exit(1);
}

$port = PLATFORM_MAIL_PORT;
$listener = @stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);

if ($listener === false) {
    error_log("Cannot listen on port {$port}: {$errstr}");
    exit(1);
}

// Messages taken per app per minute; more get a temporary failure, so a
// runaway loop in one app cannot flood the platform DB
const MESSAGES_PER_MINUTE = 60;
$sent = []; // app id => [minute, count]

// One reconnect per message when the connection was dropped (MariaDB restart, idle timeout)
$deliver = function (int $app_id, string $from, array $recipients, string $raw) use (&$store, &$sent, $connect): bool {
    $minute = intdiv(time(), 60);
    [$at, $count] = $sent[$app_id] ?? [$minute, 0];
    $count = $at === $minute ? $count + 1 : 1;
    $sent = array_filter($sent, fn(array $entry) => $entry[0] === $minute);
    $sent[$app_id] = [$minute, $count];

    if ($count > MESSAGES_PER_MINUTE) {
        error_log("App {$app_id} sent more than " . MESSAGES_PER_MINUTE . ' messages this minute, deferring');

        return false;
    }

    try {
        $store->store($app_id, $from, $recipients, $raw);
    } catch (PDOException $e) {
        error_log("Storing mail for app {$app_id} failed, reconnecting: {$e->getMessage()}");
        $store = new Mail_store($connect());
        $store->store($app_id, $from, $recipients, $raw);
    }

    error_log("Captured a message for app {$app_id} (" . strlen($raw) . ' bytes)');

    return true;
};

$authenticate = function (string $user, string $password): ?int {
    $app_id = Mail_capture::app_id($password);

    if ($app_id === null) {
        error_log('Refused a sign-in with a password that is not an app\'s');
    }

    return $app_id;
};

$hostname = 'mail.' . (parse_url((string) getenv('BASE_URL'), PHP_URL_HOST) ?: 'trongate.cloud');
$next_expiry = 0;
$server = new Mail_server(
    $listener,
    fn() => new Smtp_session($authenticate, $deliver, $hostname, Mail_capture::MAX_MESSAGE_BYTES),
    function () use (&$store, &$next_expiry) {
        if (time() < $next_expiry) {
            return;
        }

        $next_expiry = time() + 3600;

        try {
            $expired = $store->expire();

            if ($expired > 0) {
                error_log("Expired {$expired} captured messages");
            }
        } catch (PDOException $e) {
            error_log("Expiring captured mail failed: {$e->getMessage()}");
        }
    },
);

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use ($server) {
        $server->running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

error_log('Mail capture listening on port ' . PLATFORM_MAIL_PORT);
$server->run();
