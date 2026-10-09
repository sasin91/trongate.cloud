<?php

/**
 * Tests for Config_editor, which sets BASE_URL and ENV in a customer's
 * config/config.php at every apply (Trongate_config::config_php).
 *
 * Run: php tests/Config_editor_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/../modules/platform/manifests/Manifests.php';
require_once __DIR__ . '/../modules/platform/manifests/Config_editor.php';

$failures = 0;
$values = ['BASE_URL' => 'https://shop.trongate.dev/', 'ENV' => 'prod'];

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

$throws = function (callable $body, string $message): void {
    try {
        $body();
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), $message)) {
            throw new RuntimeException("Wrong error: {$e->getMessage()}");
        }

        return;
    }

    throw new RuntimeException("Expected an error containing '{$message}'");
};

/** Run the edited config in a fresh PHP and return the constants it defines */
$run = function (string $source): array {
    $file = tempnam(sys_get_temp_dir(), 'cfg');
    file_put_contents($file, $source);
    $code = 'require $argv[1]; echo json_encode(["BASE_URL" => BASE_URL, "ENV" => ENV]);';
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($file) . ' 2>&1');
    unlink($file);

    return json_decode(trim((string) preg_replace('/^.*?(?=\{)/s', '', (string) $out)), true) ?? throw new RuntimeException("Config did not run: {$out}");
};

$edit = fn(string $source): string => Config_editor::set_constants($source, $values);

$test('the stock Trongate config keeps its layout', function () use ($edit, $same, $run, $values) {
    $source = <<<'PHP'
        <?php
        //The base URL of your application
        define('BASE_URL', 'http://localhost/myapp/');

        // ENV can be 'dev' or 'prod'
        define('ENV', 'dev');
        define('DEFAULT_MODULE', 'welcome');
        PHP;

    $same(str_replace(["'http://localhost/myapp/'", "'dev')"], ["'https://shop.trongate.dev/'", "'prod')"], $source), $edit($source));
    $same($values, $run($edit($source)));
});

$test('a value over several lines is replaced whole (deploy #39)', function () use ($edit, $same, $run, $values) {
    $source = <<<'PHP'
        <?php
        define('BASE_URL', (function () {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return 'https://' . $host . '/'; // trailing slash
        })());
        define(
            'ENV',
            getenv('APP_ENV')
                ? getenv('APP_ENV')
                : 'dev'
        );
        PHP;

    $out = $edit($source);
    $same(false, str_contains($out, 'HTTP_HOST'));
    $same(false, str_contains($out, 'APP_ENV'));
    $same($values, $run($out));
});

$test('comments and strings that mention a define are left alone', function () use ($edit, $same, $run, $values) {
    $source = <<<'PHP'
        <?php
        // define('ENV', 'dev');
        /* define('BASE_URL', 'http://old/'); */
        $help = "Use define('ENV', 'dev') for development";
        define('BASE_URL', /* where the app lives */ 'http://localhost/'); // the URL
        define('ENV', 'dev'); # dev or prod
        PHP;

    $out = $edit($source);
    $same(true, str_contains($out, "// define('ENV', 'dev');"));
    $same(true, str_contains($out, "/* define('BASE_URL', 'http://old/'); */"));
    $same(true, str_contains($out, "\$help = \"Use define('ENV', 'dev') for development\";"));
    $same(true, str_contains($out, "define('BASE_URL', /* where the app lives */ 'https://shop.trongate.dev/'); // the URL"));
    $same(true, str_contains($out, "define('ENV', 'prod'); # dev or prod"));
    $same($values, $run($out));
});

$test('double quotes, \\define, DEFINE and named arguments', function () use ($edit, $same, $run, $values) {
    $source = <<<'PHP'
        <?php
        \define("BASE_URL", "http://localhost/");
        DEFINE(value: "dev", constant_name: "ENV");
        PHP;

    $out = $edit($source);
    $same(true, str_contains($out, "\\define(\"BASE_URL\", 'https://shop.trongate.dev/');"));
    $same(true, str_contains($out, "DEFINE(value: 'prod', constant_name: \"ENV\");"));
    $same($values, $run($out));
});

$test('constants defined conditionally are set in every branch', function () use ($edit, $same, $run, $values) {
    $source = <<<'PHP'
        <?php
        if (($_SERVER['HTTP_HOST'] ?? '') === 'localhost') {
            define('ENV', 'dev');
        } else {
            define('ENV', 'staging');
        }
        defined('BASE_URL') || define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost/');
        PHP;

    $out = $edit($source);
    $same(2, substr_count($out, "define('ENV', 'prod');"));
    $same(true, str_contains($out, "defined('BASE_URL') || define('BASE_URL', 'https://shop.trongate.dev/');"));
    $same($values, $run($out));
});

$test('const declarations are set; class constants and other names are not', function () use ($edit, $same, $run, $values) {
    $source = <<<'PHP'
        <?php
        const ENV = 'dev', DEFAULT_MODULE = 'welcome';
        const BASE_URL = 'http://' .
            'localhost/';
        class Settings {
            const ENV = 'class constant';
        }
        $x = Settings::class;
        PHP;

    $out = $edit($source);
    $same(true, str_contains($out, "const ENV = 'prod', DEFAULT_MODULE = 'welcome';"));
    $same(true, str_contains($out, "const BASE_URL = 'https://shop.trongate.dev/';"));
    $same(true, str_contains($out, "const ENV = 'class constant';"));
    $same($values, $run($out));
});

$test('missing constants are appended', function () use ($edit, $same, $run, $values) {
    $out = $edit("<?php\ndefine('DEFAULT_MODULE', 'welcome');\n");
    $same("<?php\ndefine('DEFAULT_MODULE', 'welcome');\ndefine('BASE_URL', 'https://shop.trongate.dev/');\ndefine('ENV', 'prod');\n", $out);
    $same($values, $run($out));
});

$test('missing constants after a closing tag reopen PHP', function () use ($edit, $same, $run, $values) {
    $out = $edit("<?php\ndefine('ENV', 'dev');\n?>\n");
    $same(true, str_contains($out, "?>\n<?php\ndefine('BASE_URL'"));
    $same($values, $run($out));
});

$test('methods, functions and other calls named define are not touched', function () use ($edit, $same) {
    $source = <<<'PHP'
        <?php
        $config->define('ENV', 'dev');
        Config::define('ENV', 'dev');
        function my_define() {}
        define('ENV', 'dev');
        define('BASE_URL', 'x');
        PHP;

    $out = $edit($source);
    $same(true, str_contains($out, "\$config->define('ENV', 'dev');"));
    $same(true, str_contains($out, "Config::define('ENV', 'dev');"));
    $same(1, substr_count($out, "'prod'"));
});

$test('quotes and backslashes in values are escaped', function () use ($same) {
    $out = Config_editor::set_constants("<?php\ndefine('ENV', 'dev');\n", ['ENV' => "it's \\ odd"]);
    $same("<?php\ndefine('ENV', 'it\\'s \\ odd');\n", $out);
    $same("it's \\ odd", eval('return ' . substr($out, strlen("<?php\ndefine('ENV', "), -3) . ';'));
});

$test('a config that is not valid PHP is refused with its line', function () use ($edit, $throws) {
    $throws(fn() => $edit("<?php\ndefine('ENV', 'dev')\ndefine('BASE_URL', 'x');\n"), 'The file is not valid PHP (Syntax error, unexpected T_STRING on line 3)');
});

$test('a define without a value is refused', function () use ($edit, $throws) {
    $throws(fn() => $edit("<?php\nif (false) { define('ENV'); }\n"), "define('ENV') on line 2 has no value");
});

$test('config_php sets the app\'s values and names the file in its errors', function () use ($same, $throws) {
    $app = ['id' => 7, 'slug' => 'shop', 'trongate_env' => 'staging'];
    $same("<?php\ndefine('BASE_URL', 'https://shop." . PLATFORM_APPS_DOMAIN . "/');\ndefine('ENV', 'staging');\n", Trongate_config::config_php("<?php\ndefine('BASE_URL', 'x');\ndefine('ENV', 'dev');\n", $app));
    $throws(fn() => Trongate_config::config_php('<?php define(', $app), 'trongate.cloud could not update config/config.php: The file is not valid PHP');
});

$test('lint reports code that parses but does not compile', function () use ($throws) {
    $throws(fn() => Config_editor::lint("<?php\nfunction f() {}\nfunction f() {}\n"), 'The regenerated file does not compile (');
    $throws(fn() => Config_editor::lint("<?php\nfunction f() {}\nfunction f() {}\n"), 'Cannot redeclare');
});

$test('verify catches a value that is not the one asked for', function () use ($throws) {
    $throws(fn() => Config_editor::verify("<?php\ndefine('ENV', 'dev');\n", ['ENV' => 'prod']), 'does not set ENV to the expected value');
    $throws(fn() => Config_editor::verify("<?php\n", ['ENV' => 'prod']), 'does not define ENV');
});

$test('Manifests::app mounts the edited config from the app Secret', function () use ($same) {
    $app = ['id' => 7, 'project_id' => 1, 'namespace' => 'project-1', 'slug' => 'shop', 'type' => 'trongate_v2', 'trongate_env' => 'prod'];
    $deployment = ['id' => 3, 'image' => 'registry/p7:abc', 'config_php' => "<?php\ndefine('ENV', 'dev');\n"];
    $manifests = Manifests::app($app, $deployment, ['host' => 'db', 'operator_name' => 'shared-1', 'password' => 'pw']);
    $by_kind = array_column($manifests, null, 'kind');

    $same(
        "<?php\ndefine('ENV', 'prod');\ndefine('BASE_URL', 'https://shop." . PLATFORM_APPS_DOMAIN . "/');\n",
        $manifests[0]['stringData']['config.php']
    );
    $mounts = array_column($by_kind['Deployment']['spec']['template']['spec']['containers'][0]['volumeMounts'], null, 'mountPath');
    $same(['name' => 'database', 'mountPath' => Manifests::APP_DIR . '/config/config.php', 'subPath' => 'config.php', 'readOnly' => true], $mounts[Manifests::APP_DIR . '/config/config.php']);
});

$test('the generated database.php is valid PHP', function () use ($same) {
    foreach (['trongate_v1', 'trongate_v2'] as $type) {
        $php = Trongate_config::database_php(['host' => "h'o\\st", 'password' => "p'w"], $type);
        Config_editor::lint($php);
        $same(true, str_contains($php, "'h\\'o\\\\st'"));
    }
});

echo $failures === 0 ? "\nAll tests passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
