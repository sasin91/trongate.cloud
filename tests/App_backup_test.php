<?php

/**
 * Tests for App_backup (names and keys of a deleted app's backup) and the
 * backup Job Manifests::backup_job builds, against what
 * deploy/k8s/platform/worker-admission-policy.yaml lets the worker create.
 *
 * Run: php tests/App_backup_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/config/platform.php';
require_once dirname(__DIR__) . '/modules/platform/manifests/Manifests.php';

test('each backup gets its own random folder', function () {
    $first = App_backup::new_prefix(12);
    $second = App_backup::new_prefix(12);

    assert_same(1, preg_match('#^app-backups/app-12/[0-9a-f]{32}/$#', $first));
    assert_same(false, $first === $second);
});

test('keys and download names per part', function () {
    assert_same('app-backups/app-12/abc/database.sql.gz', App_backup::key('app-backups/app-12/abc/', 'database'));
    assert_same('app-backups/app-12/abc/files.tar.gz', App_backup::key('app-backups/app-12/abc/', 'files'));
    assert_same('my-app-files.tar.gz', App_backup::download_name('My-App', 'files'));
    assert_same('x-database.sql.gz', App_backup::download_name('"; x', 'database'));
    assert_same('app-database.sql.gz', App_backup::download_name('"; ', 'database'));
});

test('an unknown part is refused', function () {
    try {
        App_backup::key('app-backups/app-12/abc/', '../secrets');
    } catch (InvalidArgumentException $e) {
        return;
    }

    throw new RuntimeException('Expected an InvalidArgumentException');
});

test('the backup Job matches the admission policy', function () {
    $job = Manifests::backup_job(
        ['id' => 12, 'namespace' => 'project-3'],
        ['host' => 'shared-1.databases.svc', 'port' => 3306],
        ['database' => 'https://example/db', 'files' => 'https://example/files'],
    );
    $pod = $job['spec']['template']['spec'];
    $container = $pod['containers'][0];
    $secret_refs = [];

    foreach ($container['env'] as $env) {
        if (isset($env['valueFrom'])) {
            $secret_refs[] = $env['valueFrom']['secretKeyRef']['name'];
        }
    }

    assert_same('p12-backup', $job['metadata']['name']);
    assert_same('project-3', $job['metadata']['namespace']);
    assert_same(true, Manifests::owned_by_app($job, 'project-3', 12));
    assert_same(1, count($pod['containers']));
    assert_same(PLATFORM_APP_BASE_IMAGE, $container['image']);
    assert_same(['php', '/usr/local/bin/trongate-cloud-backup.php'], $container['command']);
    assert_same(['p12-database'], $secret_refs);
    assert_same([['name' => 'files', 'persistentVolumeClaim' => ['claimName' => 'p12-files']]], $pod['volumes']);
    assert_same(false, $pod['automountServiceAccountToken']);
    assert_same(false, isset($pod['initContainers']));
});

test('the backup pod is not selected by the app Service', function () {
    $job = Manifests::backup_job(
        ['id' => 12, 'namespace' => 'project-3'],
        ['host' => 'shared-1.databases.svc', 'port' => 3306],
        ['database' => 'https://example/db', 'files' => 'https://example/files'],
    );

    assert_same('p12-backup', $job['spec']['template']['metadata']['labels']['app.kubernetes.io/name']);
});

test('the policy pins the image and script Manifests uses', function () {
    $policy = file_get_contents(dirname(__DIR__) . '/deploy/k8s/platform/worker-admission-policy.yaml');

    assert_contains("'" . PLATFORM_APP_BASE_IMAGE . "'", $policy);
    assert_contains("['php', '" . Manifests::BACKUP_SCRIPT . "']", $policy);
});

finish_tests();
