<?php

/**
 * Tests for App_env_suggestions: the environment variables suggested while
 * an app is created, read from its config/*.php.
 *
 * Run: php tests/App_env_suggestions_test.php (exits non-zero on a failure)
 */

require_once dirname(__DIR__) . '/modules/platform/manifests/App_env_suggestions.php';

$failures = 0;

$test = function (string $name, callable $body) use (&$failures): void {
    try {
        $body();
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}\n     {$e->getMessage()}\n";
    }
};

$same = function ($expected, $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Expected:\n" . var_export($expected, true) . "\nGot:\n" . var_export($actual, true));
    }
};

$test('getenv, env helpers and $_ENV are read with their literal defaults', function () use ($same) {
    $source = <<<'PHP'
    <?php
    define('API_KEY', getenv('ANTHROPIC_API_KEY'));
    define('MODEL', getenv('MODEL') ?: 'claude');
    define('RETRIES', env('RETRIES', 3));
    define('DEBUG', \getenv( "DEBUG" ) ?? false);
    $mailer = $_ENV['MAILER_DSN'] ?? 'smtp://localhost';
    $region = app_env('REGION');
    PHP;

    $same([
        ['name' => 'ANTHROPIC_API_KEY', 'default' => '', 'file' => 'config.php'],
        ['name' => 'MODEL', 'default' => 'claude', 'file' => 'config.php'],
        ['name' => 'RETRIES', 'default' => '3', 'file' => 'config.php'],
        ['name' => 'DEBUG', 'default' => 'false', 'file' => 'config.php'],
        ['name' => 'MAILER_DSN', 'default' => 'smtp://localhost', 'file' => 'config.php'],
        ['name' => 'REGION', 'default' => '', 'file' => 'config.php'],
    ], App_env_suggestions::from_files(['config.php' => $source]));
});

$test('putenv, function definitions, dynamic names and comments are not reads', function () use ($same) {
    $source = <<<'PHP'
    <?php
    putenv('SET_HERE=1');
    function env($name, $default = null) { return getenv($name) ?: $default; }
    $value = getenv($name);
    $other = getenv('A' . 'B');
    // getenv('IN_A_COMMENT')
    $text = "getenv('IN_A_STRING')";
    PHP;

    $same([], App_env_suggestions::from_files(['helpers.php' => $source]));
});

$test('Variables the platform sets and impossible names are left out', function () use ($same) {
    $source = "<?php\n\$port = getenv('PORT');\n\$bad = getenv('NOT-A-NAME');\n\$ok = getenv('OK');\n";

    $same(['OK'], array_column(App_env_suggestions::from_files(['config.php' => $source]), 'name'));
});

$test('A variable read twice is suggested once, with the first default found', function () use ($same) {
    $files = [
        'config.php' => "<?php\n\$a = getenv('SHARED');\n",
        'site.php' => "<?php\n\$b = env('SHARED', 'from site');\n\$c = env('SHARED', 'ignored');\n",
    ];

    $same([['name' => 'SHARED', 'default' => 'from site', 'file' => 'config.php']], App_env_suggestions::from_files($files));
});

$test('Only a literal default is suggested', function () use ($same) {
    $source = "<?php\n\$a = env('A', SOME_CONSTANT);\n\$b = getenv('B') ?: \$fallback;\n\$c = env('C', 'x' . 'y');\n";

    $same(['', '', ''], array_column(App_env_suggestions::from_files(['config.php' => $source]), 'default'));
});

$test('Quoted defaults are unescaped', function () use ($same) {
    $source = "<?php\n\$a = env('A', 'it\\'s');\n\$b = env('B', \"tab\\there\");\n";

    $same(["it's", "tab\there"], array_column(App_env_suggestions::from_files(['config.php' => $source]), 'default'));
});

$test('A file that is not valid PHP is skipped', function () use ($same) {
    $files = ['broken.php' => "<?php\n\$a = getenv('A'\n", 'config.php' => "<?php\n\$b = getenv('B');\n"];

    $same(['B'], array_column(App_env_suggestions::from_files($files), 'name'));
});

exit($failures === 0 ? 0 : 1);
