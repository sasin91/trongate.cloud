<?php

/**
 * Tests for the www alias of a custom domain (Manifests::www_alias): when
 * "www.{domain}" is routed, and the Gateway and redirect route
 * Manifests::app emits for it.
 *
 * Run: php tests/Www_alias_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

putenv('MAIL_KEY=test-mail-key');
require_once dirname(__DIR__) . '/config/platform.php';
require_once dirname(__DIR__) . '/modules/platform/manifests/Manifests.php';

$live = [
    'id' => 7, 'project_id' => 1, 'namespace' => 'project-1', 'slug' => 'shop', 'type' => 'trongate_v2', 'trongate_env' => 'prod',
    'verified_domain' => 'shop.example', 'domain_pointed_at' => '2026-10-30 12:00:00', 'domain_secured_at' => '2026-10-30 12:01:00',
];

$manifests = function (array $app): array {
    $files = ['config.php' => "<?php\ndefine('ENV', 'dev');\ndefine('BASE_URL', 'x');\n", 'database.php' => "<?php\n\$databases = ['default' => []];\n"];
    $deployment = ['id' => 3, 'image' => 'registry/p7:abc', 'config_files' => json_encode($files)];

    return Manifests::app($app, $deployment, ['host' => 'db', 'operator_name' => 'shared-1', 'password' => 'pw']);
};

$named = function (array $manifests, string $kind, string $name): ?array {
    foreach ($manifests as $manifest) {
        if ($manifest['kind'] === $kind && $manifest['metadata']['name'] === $name) {
            return $manifest;
        }
    }

    return null;
};

test('www is routed with every routed domain, and only then', function () use ($live) {
    assert_same('www.shop.example', Manifests::www_alias($live));
    assert_same(null, Manifests::www_alias(['domain_pointed_at' => null] + $live));
    assert_same(null, Manifests::www_alias(['verified_domain' => null] + $live));
});

test('a www domain gets no alias: its bare parent was never verified', function () use ($live) {
    assert_same(null, Manifests::www_alias(['verified_domain' => 'www.shop.example'] + $live));
});

test('gateway_hosts lists the domain, then its www alias', function () use ($live) {
    assert_same(['www.shop.example'], Manifests::gateway_hosts(['verified_domain' => 'www.shop.example'] + $live));
    assert_same(['shop.example', 'www.shop.example'], Manifests::gateway_hosts($live));
});

test('Manifests::app emits the www Gateway and a redirect route, and leaves the app route alone', function () use ($live, $manifests, $named) {
    $out = $manifests($live);
    $gateway_name = Manifests::domain_gateway_name(7, 'www.shop.example');

    $gateway = $named($out, 'Gateway', $gateway_name);
    assert_same('www.shop.example', $gateway['spec']['listeners'][0]['hostname']);
    assert_same($gateway_name . '-tls', $gateway['spec']['listeners'][0]['tls']['certificateRefs'][0]['name']);

    $redirect = $named($out, 'HTTPRoute', 'p7-www');
    assert_same(['www.shop.example'], $redirect['spec']['hostnames']);
    assert_same($gateway_name, $redirect['spec']['parentRefs'][0]['name']);
    assert_same([[
        'filters' => [[
            'type' => 'RequestRedirect',
            'requestRedirect' => ['scheme' => 'https', 'hostname' => 'shop.example', 'statusCode' => 301],
        ]],
    ]], $redirect['spec']['rules']);
    assert_same(Manifests::app_labels(7), $redirect['metadata']['labels']);

    $route = $named($out, 'HTTPRoute', 'p7-app');
    assert_same(['shop.trongate.dev', 'shop.example'], $route['spec']['hostnames']);
});

test('an unrouted domain gets no www Gateway or route', function () use ($live, $manifests, $named) {
    $out = $manifests(['domain_pointed_at' => null] + $live);

    assert_same(null, $named($out, 'HTTPRoute', 'p7-www'));
    assert_same(null, $named($out, 'Gateway', Manifests::domain_gateway_name(7, 'www.shop.example')));
});

test('destroy_app takes the redirect route down with the app', function () {
    assert_same(true, in_array(['gateway.networking.k8s.io/v1', 'HTTPRoute', 'p7-www'], Manifests::app_serving_objects(7), true));
});

finish_tests();
