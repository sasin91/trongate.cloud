<?php

require_once __DIR__ . '/Logger.php';

/**
 * One line to the shared logger, without building one each time:
 *
 *   Log::info('Impersonation started');
 *   Log::warn("[git] Move of app #12 refused: {$reason}");
 *   Log::error("[mcp] create_app failed: {$e->getMessage()}");
 *
 * The shared logger writes with PHP's error_log() (Error_log_writer), so a
 * line lands where the server keeps its errors. A script that wants its
 * lines elsewhere sets its own once: Log::use(new Logger([...])).
 */
final class Log {

    private static ?Logger $logger = null;

    static function info(string $message): void {
        self::logger()->info($message);
    }

    static function warn(string $message): void {
        self::logger()->warn($message);
    }

    static function error(string $message): void {
        self::logger()->error($message);
    }

    /**
     * Send the facade's lines to $logger from now on
     */
    static function use(Logger $logger): void {
        self::$logger = $logger;
    }

    static function logger(): Logger {
        return self::$logger ??= new Logger([new Error_log_writer()]);
    }
}
