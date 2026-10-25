<?php

/**
 * Tests for the nightly backups: Backup_file (encrypting and reading a
 * backup), Daily_backup (names, keys, when they are due) and the Job
 * Manifests::daily_backup_job builds, against what
 * deploy/k8s/platform/worker-admission-policy.yaml lets the worker create.
 *
 * Run: php tests/Daily_backup_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/config/platform.php';
require_once dirname(__DIR__) . '/modules/platform/manifests/Manifests.php';
require_once dirname(__DIR__) . '/modules/platform/live/Backup_file.php';

$dir = sys_get_temp_dir() . '/tc-daily-backup-test-' . bin2hex(random_bytes(4));
mkdir($dir);

/** Encrypts $plain with a new key pair and reads it back; returns [keys, encrypted bytes, read back] */
function round_trip(string $dir, string $plain): array {
    $keys = Backup_file::new_keys();
    file_put_contents("{$dir}/plain", $plain);
    Backup_file::encrypt("{$dir}/plain", "{$dir}/enc", $keys['public']);
    Backup_file::decrypt("{$dir}/enc", "{$dir}/back", $keys['private']);

    return [$keys, file_get_contents("{$dir}/enc"), file_get_contents("{$dir}/back")];
}

test('a backup reads back as it was, at any size', function () use ($dir) {
    $sizes = [0, 1, 1000, Backup_file::CHUNK_BYTES - 1, Backup_file::CHUNK_BYTES, Backup_file::CHUNK_BYTES * 2 + 5];

    foreach ($sizes as $size) {
        $plain = $size === 0 ? '' : random_bytes($size);
        [, $encrypted, $back] = round_trip($dir, $plain);

        assert_same(true, $back === $plain);
        assert_same(true, str_starts_with($encrypted, Backup_file::MAGIC));
        assert_same(false, $size > 16 && str_contains($encrypted, substr($plain, 0, 16)));
    }
});

test('each file gets its own key', function () use ($dir) {
    $keys = Backup_file::new_keys();
    file_put_contents("{$dir}/plain", 'same rows');
    Backup_file::encrypt("{$dir}/plain", "{$dir}/one", $keys['public']);
    Backup_file::encrypt("{$dir}/plain", "{$dir}/two", $keys['public']);

    assert_same(false, file_get_contents("{$dir}/one") === file_get_contents("{$dir}/two"));
});

test('the public key comes from the private key', function () {
    $keys = Backup_file::new_keys();

    assert_same($keys['public'], Backup_file::public_key_of($keys['private']));
});

test('another key cannot read a backup', function () use ($dir) {
    round_trip($dir, 'rows');
    $other = Backup_file::new_keys();

    assert_throws(fn() => Backup_file::decrypt("{$dir}/enc", "{$dir}/back", $other['private']), 'encrypted for another key');
});

test('a changed byte is refused', function () use ($dir) {
    [$keys] = round_trip($dir, str_repeat('row ', 1000));
    $encrypted = file_get_contents("{$dir}/enc");
    $encrypted[Backup_file::HEADER_BYTES + 20] = chr(ord($encrypted[Backup_file::HEADER_BYTES + 20]) ^ 1);
    file_put_contents("{$dir}/enc", $encrypted);

    assert_throws(fn() => Backup_file::decrypt("{$dir}/enc", "{$dir}/back", $keys['private']), 'damaged or was changed');
});

test('a cut-off backup is refused', function () use ($dir) {
    [$keys] = round_trip($dir, random_bytes(Backup_file::CHUNK_BYTES + 10));
    $encrypted = file_get_contents("{$dir}/enc");

    // Without its last chunk: every byte left is genuine, but the FINAL chunk is missing
    $first_chunk = unpack('N', substr($encrypted, Backup_file::HEADER_BYTES, 4))[1];
    file_put_contents("{$dir}/enc", substr($encrypted, 0, Backup_file::HEADER_BYTES + 4 + $first_chunk));

    assert_throws(fn() => Backup_file::decrypt("{$dir}/enc", "{$dir}/back", $keys['private']), 'cut off');
});

test('a file that is no backup is refused', function () use ($dir) {
    $keys = Backup_file::new_keys();
    file_put_contents("{$dir}/enc", str_repeat('-- plain SQL', 20));

    assert_throws(fn() => Backup_file::decrypt("{$dir}/enc", "{$dir}/back", $keys['private']), 'not an encrypted');
});

test('a malformed key is refused', function () use ($dir) {
    file_put_contents("{$dir}/plain", 'rows');

    assert_throws(fn() => Backup_file::encrypt("{$dir}/plain", "{$dir}/enc", 'not-a-key'), 'base64 32-byte key');
});

test('backup folders: per app and day, never the same twice', function () {
    $now = gmmktime(3, 0, 0, 10, 25, 2026);
    $first = Daily_backup::new_app_prefix(12, $now);
    $second = Daily_backup::new_app_prefix(12, $now);

    assert_same(1, preg_match('#^daily/app-12/2026-10-25-[0-9a-f]{16}/$#', $first));
    assert_same(false, $first === $second);
    assert_same(1, preg_match('#^daily/platform/2026-10-25-[0-9a-f]{16}/$#', Daily_backup::new_platform_prefix($now)));
});

test('one object per backup', function () {
    assert_same('daily/app-12/x/backup.tar.gz.enc', Daily_backup::key('daily/app-12/x/', 'app'));
    assert_same('daily/platform/x/platform.sql.gz.enc', Daily_backup::key('daily/platform/x/', 'platform'));

    try {
        Daily_backup::key('daily/app-12/x/', '../secrets');
    } catch (InvalidArgumentException $e) {
        return;
    }

    throw new RuntimeException('Expected an InvalidArgumentException');
});

test('backups are due from the night hour until midnight UTC', function () {
    assert_same(false, Daily_backup::due(gmmktime(Daily_backup::HOUR - 1, 59, 0, 10, 25, 2026)));
    assert_same(true, Daily_backup::due(gmmktime(Daily_backup::HOUR, 0, 0, 10, 25, 2026)));
    assert_same(true, Daily_backup::due(gmmktime(23, 59, 0, 10, 25, 2026)));
});

/** The app Deployment as Manifests::app() writes it, with a persistent path and a config file */
function app_deployment(): array {
    return [
        'spec' => ['template' => ['spec' => [
            'containers' => [[
                'name' => 'app',
                'image' => 'registry.trongate.cloud/apps/p12:abc',
                'envFrom' => [['secretRef' => ['name' => 'p12-env']]],
                'env' => [['name' => 'PORT', 'value' => '8080']],
                'volumeMounts' => [
                    ['name' => 'tmp', 'mountPath' => '/tmp'],
                    ['name' => 'config-files', 'mountPath' => '/app/config/database.php', 'subPath' => 'database.php', 'readOnly' => true],
                    ['name' => 'config', 'mountPath' => '/usr/local/etc/php/conf.d/zz-sessions.ini', 'subPath' => 'sessions.ini'],
                    ['name' => 'files', 'mountPath' => '/app/public/uploads', 'subPath' => 'public/uploads'],
                ],
            ]],
            'volumes' => [
                ['name' => 'tmp', 'emptyDir' => ['sizeLimit' => '256Mi']],
                ['name' => 'config-files', 'secret' => ['secretName' => 'p12-database', 'items' => [['key' => 'database.php', 'path' => 'database.php']]]],
                ['name' => 'config', 'emptyDir' => ['sizeLimit' => '1Mi']],
                ['name' => 'sessions', 'secret' => ['secretName' => 'p12-database']],
                ['name' => 'files', 'persistentVolumeClaim' => ['claimName' => 'p12-files']],
            ],
        ]]],
    ];
}

function daily_job(): array {
    return Manifests::daily_backup_job(
        ['id' => 12, 'namespace' => 'project-3'],
        ['host' => 'shared-1.databases.svc', 'port' => 3306],
        app_deployment(),
        'registry.trongate.cloud/platform/trongate-cloud-worker:main-1-abc',
        'https://example/upload',
        'cHVibGlj',
    );
}

test('the nightly Job: database, then the app, then upload', function () {
    $job = daily_job();
    $pod = $job['spec']['template']['spec'];
    [$database, $app] = $pod['initContainers'];
    $upload = $pod['containers'][0];

    assert_same('p12-daily-backup', $job['metadata']['name']);
    assert_same('project-3', $job['metadata']['namespace']);
    assert_same(true, Manifests::owned_by_app($job, 'project-3', 12));
    assert_same('p12-daily-backup', $job['spec']['template']['metadata']['labels']['app.kubernetes.io/name']);
    assert_same(Daily_backup::JOB_SECONDS, $job['spec']['activeDeadlineSeconds']);
    assert_same(false, $pod['automountServiceAccountToken']);

    assert_same(['php', Manifests::DAILY_BACKUP_DUMP_SCRIPT], $database['command']);
    assert_same(['php', Manifests::DAILY_BACKUP_UPLOAD_SCRIPT], $upload['command']);
    assert_same('registry.trongate.cloud/platform/trongate-cloud-worker:main-1-abc', $database['image']);
    assert_same('registry.trongate.cloud/platform/trongate-cloud-worker:main-1-abc', $upload['image']);

    // The app step: its own image and variables, its config file and persistent files
    assert_same('registry.trongate.cloud/apps/p12:abc', $app['image']);
    assert_same([['secretRef' => ['name' => 'p12-env']]], $app['envFrom']);
    assert_same(['/tmp', '/backup', '/app/config/database.php', '/app/public/uploads'], array_column($app['volumeMounts'], 'mountPath'));
    assert_contains(Manifests::DAILY_BACKUP_MODULE, $app['command'][2]);
    assert_same(false, isset($app['securityContext']['capabilities']['add']));
});

test('only the upload step gets the upload link and the key', function () {
    $pod = daily_job()['spec']['template']['spec'];
    $names = fn(array $container) => array_column($container['env'] ?? [], 'name');

    assert_same(['STAGING_DIR', 'BACKUP_PUBLIC_KEY', 'UPLOAD_URL'], $names($pod['containers'][0]));
    assert_same(false, in_array('UPLOAD_URL', $names($pod['initContainers'][0]), true));
    assert_same([], $names($pod['initContainers'][1]));
});

test('the app step mounts only its own staging folder, not the dump', function () {
    $pod = daily_job()['spec']['template']['spec'];
    $backup = array_values(array_filter($pod['initContainers'][1]['volumeMounts'], fn($m) => $m['mountPath'] === '/backup'));

    assert_same('.trongate-cloud-backup/app', $backup[0]['subPath']);
    assert_same([['name' => 'files', 'mountPath' => '/files']], $pod['initContainers'][0]['volumeMounts']);
    assert_same([['name' => 'files', 'mountPath' => '/files']], $pod['containers'][0]['volumeMounts']);
});

test('the Job mounts only the app files volume, its config Secret and emptyDirs', function () {
    $volumes = daily_job()['spec']['template']['spec']['volumes'];

    assert_same(['tmp', 'files', 'config-files'], array_column($volumes, 'name'));
    assert_same('p12-files', $volumes[1]['persistentVolumeClaim']['claimName']);
    assert_same('p12-database', $volumes[2]['secret']['secretName']);
});

test('an app without a running Deployment is refused', function () {
    assert_throws(fn() => Manifests::daily_backup_job(['id' => 12, 'namespace' => 'project-3'], ['host' => 'h'], [], 'w:1', 'u', 'k'), 'no running Deployment');
});

test('the policy pins the nightly scripts and the worker image Manifests uses', function () {
    $policy = file_get_contents(dirname(__DIR__) . '/deploy/k8s/platform/worker-admission-policy.yaml');
    $kustomization = file_get_contents(dirname(__DIR__) . '/deploy/k8s/platform/kustomization.yaml');

    assert_contains("['php', '" . Manifests::DAILY_BACKUP_DUMP_SCRIPT . "']", $policy);
    assert_contains("['php', '" . Manifests::DAILY_BACKUP_UPLOAD_SCRIPT . "']", $policy);
    assert_contains("'^p[0-9]+-daily-backup$'", $policy);

    preg_match("/newName: (\\S+trongate-cloud-worker)/", $kustomization, $m);
    assert_contains("'{$m[1]}'", $policy);
});

test('the app step backs up /app, then runs the module and survives its failure', function () {
    $script = Manifests::daily_backup_app_script();

    assert_contains('tar -cf /backup/app.tar', $script);
    assert_contains('if [ -f modules/trongate-cloud/backup/runtime.php ]', $script);
    assert_contains('BACKUP_DIR=/backup/custom timeout ' . Manifests::DAILY_BACKUP_MODULE_SECONDS . ' php', $script);
    assert_contains('/backup/custom.failed', $script);
    assert_same(true, strpos($script, 'tar -cf') < strpos($script, 'runtime.php'));
});

array_map('unlink', glob("{$dir}/*"));
rmdir($dir);

finish_tests();
