<?php

/**
 * Tests for Config_editor and Config_rule, which set the platform's values
 * (Trongate_config::rules) in an app's config/*.php at every apply.
 *
 * Run: php tests/Config_editor_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/../modules/platform/manifests/Manifests.php';
require_once __DIR__ . '/../modules/platform/manifests/Config_editor.php';

$failures = 0;
$values = ['BASE_URL' => 'https://shop.trongate.dev/', 'ENV' => 'prod'];
$rules = [new Config_rule('BASE_URL', $values['BASE_URL']), new Config_rule('ENV', $values['ENV'])];

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

/** config.php with BASE_URL and ENV set */
$edit = fn(string $source): string => Config_editor::apply(['config.php' => $source], $rules)['config.php'];

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
    $out = Config_editor::apply(['config.php' => "<?php\ndefine('ENV', 'dev');\n"], [new Config_rule('ENV', "it's \\ odd")])['config.php'];
    $same("<?php\ndefine('ENV', 'it\\'s \\ odd');\n", $out);
    $same("it's \\ odd", eval('return ' . substr($out, strlen("<?php\ndefine('ENV', "), -3) . ';'));
});

$test('a config that is not valid PHP is refused with its line', function () use ($edit, $throws) {
    $throws(fn() => $edit("<?php\ndefine('ENV', 'dev')\ndefine('BASE_URL', 'x');\n"), 'config.php is not valid PHP (Syntax error, unexpected T_STRING on line 3)');
    $throws(fn() => Config_editor::check(['login.php' => '<?php $x =']), 'login.php is not valid PHP');
});

$test('a define without a value is refused', function () use ($edit, $throws) {
    $throws(fn() => $edit("<?php\nif (false) { define('ENV'); }\n"), "config.php: define('ENV') on line 2 has no value");
});

$test('lint reports code that parses but does not compile', function () use ($throws) {
    $throws(fn() => Config_editor::lint('config.php', "<?php\nfunction f() {}\nfunction f() {}\n"), 'config.php does not compile after editing (');
    $throws(fn() => Config_editor::lint('config.php', "<?php\nfunction f() {}\nfunction f() {}\n"), 'Cannot redeclare');
});

$test('verify catches a value that is not the one asked for', function () use ($throws) {
    $rule = [new Config_rule('ENV', 'prod')];
    $throws(fn() => Config_editor::verify(['config.php' => "<?php\ndefine('ENV', 'dev');\n"], $rule), 'config.php does not set ENV to the expected value');
    $throws(fn() => Config_editor::verify(['config.php' => "<?php\n"], $rule), 'config.php does not set ENV after editing');
});

// --- Array keys and file scope ------------------------------------------------

$database_rules = fn(string $host = 'shared-1.databases') => [
    new Config_rule("\$databases['default']['host']", $host, 'database.php'),
    new Config_rule("\$databases['default']['port']", '3306', 'database.php'),
    new Config_rule("\$databases['default']['password']", "p'w", 'database.php'),
];

$test('array elements are set inside the assigned literal', function () use ($same, $database_rules) {
    $source = <<<'PHP'
        <?php
        $databases = [
            'default' => [
                'host' => getenv('DB_HOST') ?: '127.0.0.1', // local
                'port' => '3306',
                'user' => 'root',
                'password' => '',
            ],
            'analytics' => ['host' => 'other'],
        ];
        PHP;

    $same(['database.php' => <<<'PHP'
        <?php
        $databases = [
            'default' => [
                'host' => 'shared-1.databases', // local
                'port' => '3306',
                'user' => 'root',
                'password' => 'p\'w',
            ],
            'analytics' => ['host' => 'other'],
        ];
        PHP], Config_editor::apply(['database.php' => $source], $database_rules()));
});

$test('array elements are set through partial and full assignments', function () use ($same) {
    $source = <<<'PHP'
        <?php
        $config['trongate_email'] = [
            'smtp_host' => '',
            'smtp_port' => 465,
        ];
        $config['trongate_email']['smtp_port'] = 587;
        PHP;

    $out = Config_editor::apply(['trongate_email.php' => $source], [
        new Config_rule("\$config['trongate_email']['smtp_host']", 'mail.example.com'),
        new Config_rule("\$config['trongate_email']['smtp_port']", 2525),
    ])['trongate_email.php'];

    $same(true, str_contains($out, "'smtp_host' => 'mail.example.com',"));
    $same(true, str_contains($out, "'smtp_port' => 2525,"));
    $same(true, str_contains($out, "\$config['trongate_email']['smtp_port'] = 2525;"));
});

$test('a missing array element is appended as an assignment', function () use ($same) {
    $out = Config_editor::apply(
        ['database.php' => "<?php\n\$databases = ['default' => ['host' => 'x']];\n"],
        [new Config_rule("\$databases['default']['user']", 'p7', 'database.php')],
    );
    $same(['database.php' => "<?php\n\$databases = ['default' => ['host' => 'x']];\n\$databases['default']['user'] = 'p7';\n"], $out);
});

$test('a file the app lacks is created', function () use ($same, $database_rules) {
    $out = Config_editor::apply(['config.php' => "<?php\n"], $database_rules('db'));
    $same(['database.php' => "<?php\n\$databases['default']['host'] = 'db';\n\$databases['default']['port'] = '3306';\n\$databases['default']['password'] = 'p\\'w';\n"], $out);
});

$test('a file scope keeps a rule out of other files', function () use ($same) {
    $files = [
        'config.php' => "<?php\ndefine('HOST', 'app-host');\n",
        'database.php' => "<?php\ndefine('HOST', 'localhost');\n",
    ];
    $same(['database.php' => "<?php\ndefine('HOST', 'db');\n"], Config_editor::apply($files, [new Config_rule('HOST', 'db', 'database.php')]));
});

$test('an unscoped rule edits whichever files define the key, and only those', function () use ($same) {
    $files = [
        'config.php' => "<?php\ndefine('ENV', 'dev');\n",
        'site_owner.php' => "<?php\n//Settings to do with the identification of the site owner\ndefine('WEBSITE_NAME', 'Trongate v2');\n",
        'custom_routing.php' => "<?php\n\$routes = [];\n",
    ];
    $same(
        ['site_owner.php' => "<?php\n//Settings to do with the identification of the site owner\ndefine('WEBSITE_NAME', 'Shop');\n"],
        Config_editor::apply($files, [new Config_rule('WEBSITE_NAME', 'Shop')]),
    );
});

$test('rule keys and files are validated', function () use ($same) {
    foreach (['$x', "\$x[\"a\"]", 'A-B', "\$x['a'];"] as $key) {
        try {
            new Config_rule($key, 'v');
            throw new RuntimeException("Accepted {$key}");
        } catch (InvalidArgumentException) {
        }
    }

    try {
        new Config_rule('ENV', 'v', '../config.php');
        throw new RuntimeException('Accepted a path');
    } catch (InvalidArgumentException) {
    }

    $same(['databases', ['default', 'host']], (fn(Config_rule $r) => [$r->variable, $r->path])(new Config_rule("\$databases['default']['host']", 'x')));
});

// --- The platform's rules -------------------------------------------------------

$db = ['host' => 'shared-1.databases', 'port' => '3306', 'user' => 'tc_p7', 'password' => 'pw', 'database' => 'tc_p7'];

$test('a v2 app gets BASE_URL, ENV and $databases set', function () use ($same, $db) {
    $files = [
        'config.php' => "<?php\ndefine('BASE_URL', 'x');\ndefine('ENV', 'dev');\n",
        'database.php' => "<?php\n\$databases = ['default' => ['host' => '', 'port' => '', 'user' => '', 'password' => '', 'database' => '']];\n",
        'site_owner.php' => "<?php\ndefine('WEBSITE_NAME', 'Shop');\n",
    ];
    $out = Trongate_config::config_files($files, ['id' => 7, 'slug' => 'shop', 'trongate_env' => 'staging'], $db);

    $same(['config.php', 'database.php'], array_keys($out));
    $same("<?php\ndefine('BASE_URL', 'https://shop." . PLATFORM_APPS_DOMAIN . "/');\ndefine('ENV', 'staging');\n", $out['config.php']);
    $same("<?php\n\$databases = ['default' => ['host' => 'shared-1.databases', 'port' => '3306', 'user' => 'tc_p7', 'password' => 'pw', 'database' => 'tc_p7']];\n", $out['database.php']);
});

$test('a v1 app gets the database constants', function () use ($same, $db) {
    $files = [
        'config.php' => "<?php\ndefine('BASE_URL', 'x');\ndefine('ENV', 'dev');\n",
        'database.php' => "<?php\ndefine('HOST', '127.0.0.1');\ndefine('PORT', '3306');\ndefine('USER', 'root');\ndefine('PASSWORD', '');\ndefine('DATABASE', 'app');\n",
    ];
    $out = Trongate_config::config_files($files, ['id' => 7, 'slug' => 'shop', 'type' => 'trongate_v1'], $db);
    $same("<?php\ndefine('HOST', 'shared-1.databases');\ndefine('PORT', '3306');\ndefine('USER', 'tc_p7');\ndefine('PASSWORD', 'pw');\ndefine('DATABASE', 'tc_p7');\n", $out['database.php']);
});

$test('config_files names the file in its errors', function () use ($throws, $db) {
    $throws(fn() => Trongate_config::config_files(['database.php' => '<?php $databases = ['], ['id' => 7, 'slug' => 'shop'], $db), 'trongate.cloud could not set its values in config/: database.php is not valid PHP');
});

$test('Manifests::app mounts the edited files from the app Secret', function () use ($same) {
    $app = ['id' => 7, 'project_id' => 1, 'namespace' => 'project-1', 'slug' => 'shop', 'type' => 'trongate_v2', 'trongate_env' => 'prod'];
    $files = ['config.php' => "<?php\ndefine('ENV', 'dev');\ndefine('BASE_URL', 'x');\n", 'database.php' => "<?php\n\$databases = ['default' => []];\n", 'site_owner.php' => "<?php\n"];
    $deployment = ['id' => 3, 'image' => 'registry/p7:abc', 'config_files' => json_encode($files)];
    $manifests = Manifests::app($app, $deployment, ['host' => 'db', 'operator_name' => 'shared-1', 'password' => 'pw']);
    $by_kind = array_column($manifests, null, 'kind');

    $same("<?php\ndefine('ENV', 'prod');\ndefine('BASE_URL', 'https://shop." . PLATFORM_APPS_DOMAIN . "/');\n", $manifests[0]['stringData']['config.php']);
    $same(false, isset($manifests[0]['stringData']['site_owner.php']));

    $mounts = array_column($by_kind['Deployment']['spec']['template']['spec']['containers'][0]['volumeMounts'], null, 'mountPath');
    $same(['name' => 'config-files', 'mountPath' => Manifests::APP_DIR . '/config/config.php', 'subPath' => 'config.php', 'readOnly' => true], $mounts[Manifests::APP_DIR . '/config/config.php']);
    $same(true, isset($mounts[Manifests::APP_DIR . '/config/database.php']));
    $same(false, isset($mounts[Manifests::APP_DIR . '/config/site_owner.php']));
});

echo $failures === 0 ? "\nAll tests passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
