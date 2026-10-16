<?php

/**
 * Tests for Object_storage's signed links, against the presigned URL example
 * in the AWS documentation ("Authenticating Requests: Using Query Parameters").
 *
 * Run: php tests/Object_storage_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/modules/platform/live/Object_storage.php';

$aws = new Object_storage(
    'https://s3.amazonaws.com',
    'us-east-1',
    'examplebucket',
    'AKIAIOSFODNN7EXAMPLE',
    'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
);
$may_24_2013 = gmmktime(0, 0, 0, 5, 24, 2013);

test('signs a GET link like the AWS documentation example', function () use ($aws, $may_24_2013) {
    $link = $aws->link('GET', 'test.txt', 86400, [], $may_24_2013);

    assert_same(
        'https://examplebucket.s3.amazonaws.com/test.txt'
        . '?X-Amz-Algorithm=AWS4-HMAC-SHA256'
        . '&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request'
        . '&X-Amz-Date=20130524T000000Z'
        . '&X-Amz-Expires=86400'
        . '&X-Amz-SignedHeaders=host'
        . '&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
        $link
    );
});

test('keeps the slashes of a key and encodes the rest', function () use ($aws) {
    assert_same('/backups/app-7/a%20b%2Bc.sql.gz', $aws->path('backups/app-7/a b+c.sql.gz'));
});

test('signs extra query parameters in name order', function () use ($aws, $may_24_2013) {
    $link = $aws->link('GET', 'test.txt', 600, ['response-content-disposition' => 'attachment; filename="db.sql.gz"'], $may_24_2013);

    assert_contains('X-Amz-SignedHeaders=host&response-content-disposition=attachment%3B%20filename%3D%22db.sql.gz%22&X-Amz-Signature=', $link);
});

test('refuses a link valid for longer than SigV4 allows', function () use ($aws) {
    $refused = false;

    try {
        $aws->link('GET', 'test.txt', Object_storage::MAX_EXPIRES_SECONDS + 1);
    } catch (InvalidArgumentException $e) {
        $refused = true;
    }

    assert_same(true, $refused);
});

finish_tests();
