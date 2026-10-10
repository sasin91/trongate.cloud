<?php

/**
 * Startup probe of the panel (deploy/k8s/platform/app.yaml): exits 0 once the
 * panel can sign in to Valkey as its session user (Valkey_sessions).
 *
 *   php bin/check-sessions.php
 *
 * The worker creates that user when it starts, so a new panel pod takes no
 * traffic before then; the old pods keep serving until it does.
 */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../engine/Valkey_sessions.php';

$user = Valkey_sessions::PLATFORM_USER;

try {
    $redis = new Redis();
    $redis->connect(VALKEY_HOST, VALKEY_PORT, 2);
    $redis->auth([$user, Valkey_sessions::password($user)]);
    $redis->ping();
} catch (Throwable $e) {
    fwrite(STDERR, "Valkey session user {$user} not usable: {$e->getMessage()}\n");
    exit(1);
}
