<?php

/**
 * Call $callback until it returns without throwing, at most $times attempts,
 * like Laravel's retry(). $callback gets the attempt number (from 1).
 *
 * $sleep_ms is a fixed delay or fn(int $attempt): int for a backoff. $when
 * decides whether an exception is worth another attempt (default: any). The
 * last exception is rethrown.
 */
function retry(int $times, callable $callback, int|Closure $sleep_ms = 0, ?callable $when = null): mixed {
    $attempt = 0;

    do {
        $attempt++;

        try {
            return $callback($attempt);
        } catch (Throwable $e) {
            if ($attempt >= $times || ($when !== null && !$when($e, $attempt))) {
                throw $e;
            }

            $delay = $sleep_ms instanceof Closure ? $sleep_ms($attempt) : $sleep_ms;

            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }
    } while (true);
}
