<?php
require_once __DIR__ . '/Error_report.php';

/**
 * Error pages: what a visitor sees when a page doesn't exist or a request
 * fails, instead of a bare 404 or 500. Fetch() calls get {"error": "..."}
 * JSON instead of a page. Failures are still logged.
 *
 * Self-contained so any Trongate app can use it; see README.md for the two
 * lines of wiring.
 */
class Error_pages extends Trongate {

    /** The ERROR_404 handler: define('ERROR_404', 'error_pages/not_found'). */
    public function not_found(): void {
        // A fresh install with no BASE_URL yet gets Trongate's URL setup form.
        if (BASE_URL === '****' && strtolower(ENV) === 'dev') {
            $this->module('templates');
            $this->templates->error_404();
            return;
        }
        if (!headers_sent()) {
            http_response_code(404);
        }
        $report = new Error_report(404, 'Page not found', "There's no page at this address. It may have moved, or the link may be mistyped.");
        if (Error_report::wants_json($_SERVER)) {
            self::send_json($report);
            return;
        }
        $this->view('not_found', ['report' => $report, 'home' => BASE_URL]);
    }

    /** Called once from engine/ignition.php, after the autoloader. */
    public static function _register(): void {
        set_exception_handler([self::class, '_on_exception']);
        register_shutdown_function([self::class, '_on_shutdown']);
    }

    public static function _on_exception(Throwable $e): void {
        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        self::send(Error_report::from_exception($e, self::shows_detail()));
    }

    /** A fatal error (timeout, out of memory) ends the request without an exception. PHP has logged it. */
    public static function _on_shutdown(): void {
        $error = error_get_last();
        if ($error === null || ($error['type'] & Error_report::FATAL) === 0 || headers_sent()) {
            return;
        }
        self::send(Error_report::from_fatal($error, self::shows_detail()));
    }

    private static function shows_detail(): bool {
        return Error_report::shows_detail(defined('ENV') ? ENV : '', $_SERVER);
    }

    private static function send(Error_report $report): void {
        if (!headers_sent()) {
            http_response_code($report->status);
            if ($report->status === 503) {
                header('Retry-After: 60');
            }
        }
        if (Error_report::wants_json($_SERVER)) {
            self::send_json($report);
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        // The view is required directly, not through Trongate::view(), so the
        // page still works when the failure came before the helpers loaded.
        $home = defined('BASE_URL') ? BASE_URL : '/';
        ob_start();
        try {
            require __DIR__ . '/views/server_error.php';
            echo ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            // The page itself failed (say, a broken view): words are enough.
            echo htmlspecialchars($report->title . '. ' . $report->message, ENT_QUOTES, 'UTF-8');
        }
    }

    private static function send_json(Error_report $report): void {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => $report->message]);
    }
}
