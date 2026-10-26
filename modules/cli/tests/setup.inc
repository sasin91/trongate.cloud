<?php
require_once __DIR__ . '/../Env.php';
require_once __DIR__ . '/../Process.php';

/** What $body threw, or 'nothing thrown' */
function thrown(callable $body): string {
    try {
        $body();
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }

    return 'nothing thrown';
}
