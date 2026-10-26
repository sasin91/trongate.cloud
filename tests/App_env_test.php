<?php

/**
 * Tests for App_env: the NAME=value lines an app's environment variables are
 * pasted and edited as.
 *
 * Run: php tests/App_env_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/modules/platform/manifests/App_env.php';

function parse_error(string $text): string {
    try {
        App_env::parse_lines($text);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return 'no error';
}

test('A pasted .env file becomes its variables', function () {
    $text = "# Mail\r\nMAIL_FROM=hello@example.com\n\nexport APP_ENV=production\nGREETING=\"Hi there\"\nNAME='single'\nURL=https://example.com/?a=b\nEMPTY=\n";

    assert_same([
        'MAIL_FROM' => 'hello@example.com',
        'APP_ENV' => 'production',
        'GREETING' => 'Hi there',
        'NAME' => 'single',
        'URL' => 'https://example.com/?a=b',
        'EMPTY' => '',
    ], App_env::parse_lines($text));
});

test('A later line with the same name wins', function () {
    assert_same(['A' => '2'], App_env::parse_lines("A=1\nA=2"));
});

test('A line without =, a bad name or a reserved name is refused', function () {
    assert_same('Write A as A=value.', parse_error('A'));
    assert_same('Names may only contain letters, numbers and underscores, and may not start with a number.', parse_error('1A=x'));
    assert_same('PORT is set by the platform and cannot be changed.', parse_error('PORT=80'));
});

test('A value over the size limit is refused', function () {
    assert_same('The value of BIG is too long.', parse_error('BIG=' . str_repeat('x', App_env::MAX_VALUE_BYTES + 1)));
});

test('Variables written as lines read back the same', function () {
    $env = [
        'PLAIN' => 'value',
        'SPACED' => ' padded ',
        'QUOTED' => '"kept"',
        'HALF' => '"open',
        'EQUALS' => 'a=b',
        'EMPTY' => '',
    ];

    assert_same($env, App_env::parse_lines(App_env::to_lines($env)));
});

test('The deploy reference names the kind of variable', function () {
    assert_same('secrets:app,7,env', App_env::reference(7));
    assert_same('secrets:app,7,env_plain', App_env::reference(7, App_env::PLAIN_SERVICE));
});

finish_tests();
