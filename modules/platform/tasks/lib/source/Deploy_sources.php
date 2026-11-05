<?php

require_once __DIR__ . '/Github_source.php';
require_once __DIR__ . '/Internal_source.php';

/**
 * The Deploy_source of an app, by its source_provider
 */
final class Deploy_sources {

    /**
     * @param array<string, mixed> $app
     */
    static function for_app(array $app): Deploy_source {
        $provider = (string) ($app['source_provider'] ?? '');

        return match ($provider) {
            'github' => Github_source::for_app($app),
            Git_repo::PROVIDER => Internal_source::for_app($app),
            default => throw new RuntimeException("Unsupported source provider: {$provider}"),
        };
    }
}
