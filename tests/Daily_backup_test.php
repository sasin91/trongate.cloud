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

test('keys per part, and the platform has its database only', function () {
    assert_same('daily/app-12/x/database.sql.gz.enc', Daily_backup::key('daily/app-12/x/', 'app', 'database'));
    assert_same('daily/app-12/x/files.tar.gz.enc', Daily_backup::key('daily/app-12/x/', 'app', 'files'));
    assert_same('daily/platform/x/platform.sql.gz.enc', Daily_backup::key('daily/platform/x/', 'platform', 'database'));

    try {
        Daily_backup::key('daily/platform/x/', 'platform', 'files');
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

test('the nightly Job matches the admission policy', function () {
    $job = Manifests::daily_backup_job(
        ['id' => 12, 'namespace' => 'project-3'],
        ['host' => 'shared-1.databases.svc', 'port' => 3306],
        ['database' => 'https://example/db', 'files' => 'https://example/files'],
        'cHVibGlj',
    );
    $pod = $job['spec']['template']['spec'];
    $container = $pod['containers'][0];
    $secret_refs = [];
    $env = [];

    foreach ($container['env'] as $variable) {
        if (isset($variable['valueFrom'])) {
            $secret_refs[] = $variable['valueFrom']['secretKeyRef']['name'];
        } else {
            $env[$variable['name']] = $variable['value'];
        }
    }

    assert_same('p12-daily-backup', $job['metadata']['name']);
    assert_same('project-3', $job['metadata']['namespace']);
    assert_same(true, Manifests::owned_by_app($job, 'project-3', 12));
    assert_same('p12-daily-backup', $job['spec']['template']['metadata']['labels']['app.kubernetes.io/name']);
    assert_same(PLATFORM_APP_BASE_IMAGE, $container['image']);
    assert_same('Always', $container['imagePullPolicy']);
    assert_same(['php', '/usr/local/bin/trongate-cloud-daily-backup.php'], $container['command']);
    assert_same(['p12-database'], $secret_refs);
    assert_same('cHVibGlj', $env['BACKUP_PUBLIC_KEY']);
    assert_same('tc_p12', $env['DB_NAME']);
    assert_same([['name' => 'files', 'persistentVolumeClaim' => ['claimName' => 'p12-files']]], $pod['volumes']);
    assert_same(false, $pod['automountServiceAccountToken']);
    assert_same(Daily_backup::JOB_SECONDS, $job['spec']['activeDeadlineSeconds']);
});

test('the policy pins the nightly script Manifests uses', function () {
    $policy = file_get_contents(dirname(__DIR__) . '/deploy/k8s/platform/worker-admission-policy.yaml');

    assert_contains("['php', '" . Manifests::DAILY_BACKUP_SCRIPT . "']", $policy);
    assert_contains("'^p[0-9]+-daily-backup$'", $policy);
});

test('the app base image carries both scripts and their classes', function () {
    $dockerfile = file_get_contents(dirname(__DIR__) . '/docker/app-base/Dockerfile');

    assert_contains('/usr/local/bin/trongate-cloud-daily-backup.php', $dockerfile);
    assert_contains(Manifests::BACKUP_SCRIPT, $dockerfile);

    foreach (['Database_dump', 'Backup_file', 'Object_storage'] as $class) {
        assert_contains("modules/platform/live/{$class}.php", $dockerfile);
    }
});

array_map('unlink', glob("{$dir}/*"));
rmdir($dir);

finish_tests();
