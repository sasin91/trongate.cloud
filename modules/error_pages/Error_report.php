<?php
/**
 * What went wrong, in words for the visitor: the HTTP status, a heading and
 * a sentence, without internals. Built from an uncaught exception or from a
 * fatal error (error_get_last()). Plain PHP, no framework, so it can be
 * tested on its own.
 */
final class Error_report {

    /** Fatal error types PHP can still report from a shutdown function. */
    public const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $message,
        public readonly string $detail = ''
    ) {}

    public static function from_exception(Throwable $e, bool $with_detail = false): self {
        $detail = $with_detail
            ? get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()
            : '';
        if (self::is_schema_missing($e)) {
            return new self(503, 'Updating', "The site was just updated and its database hasn't caught up yet, so this page can't load. Please try again in a little while.", $detail);
        }
        return new self(500, 'Something went wrong', "This page couldn't load because of an error on our side. It has been logged. Please try again.", $detail);
    }

    /** @param array{type:int,message:string,file:string,line:int} $error */
    public static function from_fatal(array $error, bool $with_detail = false): self {
        $detail = $with_detail ? $error['message'] . "\n" . $error['file'] . ':' . $error['line'] : '';
        if (str_starts_with($error['message'], 'Maximum execution time')) {
            return new self(503, 'That took too long', 'The server stopped this request because it ran longer than it is allowed to. Please try again; if it keeps happening, try with less at once.', $detail);
        }
        return new self(500, 'Something went wrong', "This page couldn't load because of an error on our side. It has been logged. Please try again.", $detail);
    }

    /** A missing table (42S02) or column (42S22): the app's schema wasn't applied after a deploy. */
    public static function is_schema_missing(Throwable $e): bool {
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof PDOException && in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
                return true;
            }
        }
        return false;
    }

    /** A fetch() call or API client: answer with JSON, not a page. */
    public static function wants_json(array $server): bool {
        $mode = strtolower($server['HTTP_SEC_FETCH_MODE'] ?? '');
        return in_array($mode, ['cors', 'same-origin'], true)
            || strtolower($server['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains(strtolower($server['CONTENT_TYPE'] ?? ''), 'application/json')
            || str_contains(strtolower($server['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /**
     * The exception itself is shown only in development and only to the
     * machine the app runs on, so a public site left on ENV dev doesn't leak
     * file paths and stack traces.
     */
    public static function shows_detail(string $env, array $server): bool {
        return strtolower($env) === 'dev'
            && in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    }
}
