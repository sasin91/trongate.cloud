<?php

/**
 * Tests for Config_editor and Config_rule, which set the platform's values
 * (Trongate_config::rules) in an app's config/*.php at every apply.
 *
 * Run: php tests/Config_editor_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

putenv('APP_KEY=test-key-for-config-rules');
putenv('MAIL_KEY=test-mail-key');

require_once __DIR__ . '/../modules/platform/manifests/Manifests.php';
require_once __DIR__ . '/../modules/platform/manifests/Config_editor.php';

$values = ['BASE_URL' => 'https://shop.trongate.dev/', 'ENV' => 'prod'];
$rules = [new Config_rule('BASE_URL', $values['BASE_URL']), new Config_rule('ENV', $values['ENV'])];

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

test('the stock Trongate config keeps its layout', function () use ($edit, $run, $values) {
    $source = <<<'PHP'
        <?php
        //The base URL of your application
        define('BASE_URL', 'http://localhost/myapp/');

        // ENV can be 'dev' or 'prod'
        define('ENV', 'dev');
        define('DEFAULT_MODULE', 'welcome');
        PHP;

    assert_same(str_replace(["'http://localhost/myapp/'", "'dev')"], ["'https://shop.trongate.dev/'", "'prod')"], $source), $edit($source));
    assert_same($values, $run($edit($source)));
});

test('a value over several lines is replaced whole (deploy #39)', function () use ($edit, $run, $values) {
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
    assert_same(false, str_contains($out, 'HTTP_HOST'));
    assert_same(false, str_contains($out, 'APP_ENV'));
    assert_same($values, $run($out));
});

test('comments and strings that mention a define are left alone', function () use ($edit, $run, $values) {
    $source = <<<'PHP'
        <?php
        // define('ENV', 'dev');
        /* define('BASE_URL', 'http://old/'); */
        $help = "Use define('ENV', 'dev') for development";
        define('BASE_URL', /* where the app lives */ 'http://localhost/'); // the URL
        define('ENV', 'dev'); # dev or prod
        PHP;

    $out = $edit($source);
    assert_same(true, str_contains($out, "// define('ENV', 'dev');"));
    assert_same(true, str_contains($out, "/* define('BASE_URL', 'http://old/'); */"));
    assert_same(true, str_contains($out, "\$help = \"Use define('ENV', 'dev') for development\";"));
    assert_same(true, str_contains($out, "define('BASE_URL', /* where the app lives */ 'https://shop.trongate.dev/'); // the URL"));
    assert_same(true, str_contains($out, "define('ENV', 'prod'); # dev or prod"));
    assert_same($values, $run($out));
});

test('double quotes, \\define, DEFINE and named arguments', function () use ($edit, $run, $values) {
    $source = <<<'PHP'
        <?php
        \define("BASE_URL", "http://localhost/");
        DEFINE(value: "dev", constant_name: "ENV");
        PHP;

    $out = $edit($source);
    assert_same(true, str_contains($out, "\\define(\"BASE_URL\", 'https://shop.trongate.dev/');"));
    assert_same(true, str_contains($out, "DEFINE(value: 'prod', constant_name: \"ENV\");"));
    assert_same($values, $run($out));
});

test('constants defined conditionally are set in every branch', function () use ($edit, $run, $values) {
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
    assert_same(2, substr_count($out, "define('ENV', 'prod');"));
    assert_same(true, str_contains($out, "defined('BASE_URL') || define('BASE_URL', 'https://shop.trongate.dev/');"));
    assert_same($values, $run($out));
});

test('const declarations are set; class constants and other names are not', function () use ($edit, $run, $values) {
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
    assert_same(true, str_contains($out, "const ENV = 'prod', DEFAULT_MODULE = 'welcome';"));
    assert_same(true, str_contains($out, "const BASE_URL = 'https://shop.trongate.dev/';"));
    assert_same(true, str_contains($out, "const ENV = 'class constant';"));
    assert_same($values, $run($out));
});

test('missing constants are appended', function () use ($edit, $run, $values) {
    $out = $edit("<?php\ndefine('DEFAULT_MODULE', 'welcome');\n");
    assert_same("<?php\ndefine('DEFAULT_MODULE', 'welcome');\ndefine('BASE_URL', 'https://shop.trongate.dev/');\ndefine('ENV', 'prod');\n", $out);
    assert_same($values, $run($out));
});

test('missing constants after a closing tag reopen PHP', function () use ($edit, $run, $values) {
    $out = $edit("<?php\ndefine('ENV', 'dev');\n?>\n");
    assert_same(true, str_contains($out, "?>\n<?php\ndefine('BASE_URL'"));
    assert_same($values, $run($out));
});

test('methods, functions and other calls named define are not touched', function () use ($edit) {
    $source = <<<'PHP'
        <?php
        $config->define('ENV', 'dev');
        Config::define('ENV', 'dev');
        function my_define() {}
        define('ENV', 'dev');
        define('BASE_URL', 'x');
        PHP;

    $out = $edit($source);
    assert_same(true, str_contains($out, "\$config->define('ENV', 'dev');"));
    assert_same(true, str_contains($out, "Config::define('ENV', 'dev');"));
    assert_same(1, substr_count($out, "'prod'"));
});

test('quotes and backslashes in values are escaped', function () {
    $out = Config_editor::apply(['config.php' => "<?php\ndefine('ENV', 'dev');\n"], [new Config_rule('ENV', "it's \\ odd")])['config.php'];
    assert_same("<?php\ndefine('ENV', 'it\\'s \\ odd');\n", $out);
    assert_same("it's \\ odd", eval('return ' . substr($out, strlen("<?php\ndefine('ENV', "), -3) . ';'));
});

/** The Config_error $body throws, as to_array() */
$config_error = function (callable $body): array {
    try {
        $body();
    } catch (Config_error $e) {
        return $e->to_array();
    }

    throw new RuntimeException('Expected a Config_error');
};

test('a config that is not valid PHP names the file and line, for the customer to fix', function () use ($edit, $config_error) {
    $error = $config_error(fn() => $edit("<?php\ndefine('ENV', 'dev')\ndefine('BASE_URL', 'x');\n"));
    assert_same(['reason' => 'Not valid PHP: Syntax error, unexpected T_STRING.', 'action' => Config_error::FIX_FILE, 'file' => 'config.php', 'line' => 3], array_diff_key($error, ['hint' => 0]));
    assert_same(Config_error::FIX_FILE, $config_error(fn() => Config_editor::check(['login.php' => '<?php $x =']))['action']);
});

test('a define without a value is the customer\'s to fix', function () use ($edit, $config_error) {
    $error = $config_error(fn() => $edit("<?php\nif (false) { define('ENV'); }\n"));
    assert_same(["define('ENV') has no value.", 'config.php', 2, Config_error::FIX_FILE], [$error['reason'], $error['file'], $error['line'], $error['action']]);
});

test('a value our edit did not set is a platform error', function () use ($config_error) {
    $rule = [new Config_rule('ENV', 'prod')];
    assert_same(['ENV does not hold the expected value after editing.', Config_error::PLATFORM], array_values(array_intersect_key($config_error(fn() => Config_editor::verify(['config.php' => "<?php\ndefine('ENV', 'dev');\n"], $rule)), ['reason' => 0, 'action' => 0])));
    assert_same(['ENV is not set after editing.', Config_error::PLATFORM], array_values(array_intersect_key($config_error(fn() => Config_editor::verify(['config.php' => "<?php\n"], $rule)), ['reason' => 0, 'action' => 0])));
});

test('the message carries where, why and what to do', function () {
    assert_same(
        'config/database.php line 4: Not valid PHP. Fix the file in your repository and push; your app keeps running its previous deployment until then.',
        (new Config_error('Not valid PHP.', Config_error::FIX_FILE, 'database.php', 4))->getMessage(),
    );
    assert_same('Deploy the app again; your app keeps running its current deployment until then.', (new Config_error('Old.', Config_error::REDEPLOY))->hint());
});

test('files that could exhaust the worker are refused before parsing', function () use ($config_error) {
    $limits = fn(array $files) => $config_error(fn() => Config_editor::check($files));

    assert_same('Larger than 256 KiB.', $limits(['config.php' => '<?php // ' . str_repeat('x', 256 * 1024)])['reason']);
    assert_same('The PHP files in config/ are larger than 512 KiB together.', $limits(array_fill_keys(['a.php', 'b.php', 'c.php'], '<?php // ' . str_repeat('x', 200 * 1024)))['reason']);
    assert_same('More than 50 PHP files in config/.', $limits(array_fill_keys(array_map(fn($i) => "f{$i}.php", range(1, 51)), "<?php\n"))['reason']);
    assert_same(['Nested more than 64 levels deep.', 2], array_values(array_intersect_key($limits(['config.php' => "<?php\n\$x = " . str_repeat('[', 65) . str_repeat(']', 65) . ';']), ['reason' => 0, 'line' => 0])));
    assert_same('Not saved as UTF-8.', $limits(['config.php' => "<?php // \xff"])['reason']);
    assert_same('File names in config/ may only use letters, digits, - and _.', $limits(['my file.php' => "<?php\n"])['reason']);
    Config_editor::check(['config.php' => "<?php\n\$x = " . str_repeat('[', 64) . str_repeat(']', 64) . ';']);
});

// --- Array keys and file scope ------------------------------------------------

$database_rules = fn(string $host = 'shared-1.databases') => [
    new Config_rule('databases.default.host', $host, 'database.php'),
    new Config_rule('databases.default.port', '3306', 'database.php'),
    new Config_rule('databases.default.password', "p'w", 'database.php'),
];

test('array elements are set inside the assigned literal', function () use ($database_rules) {
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

    assert_same(['database.php' => <<<'PHP'
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

test('array elements are set through partial and full assignments', function () {
    $source = <<<'PHP'
        <?php
        $config['trongate_email'] = [
            'smtp_host' => '',
            'smtp_port' => 465,
        ];
        $config['trongate_email']['smtp_port'] = 587;
        PHP;

    $out = Config_editor::apply(['trongate_email.php' => $source], [
        new Config_rule('config.trongate_email.smtp_host', 'mail.example.com'),
        new Config_rule('config.trongate_email.smtp_port', 2525),
    ])['trongate_email.php'];

    assert_same(true, str_contains($out, "'smtp_host' => 'mail.example.com',"));
    assert_same(true, str_contains($out, "'smtp_port' => 2525,"));
    assert_same(true, str_contains($out, "\$config['trongate_email']['smtp_port'] = 2525;"));
});

test('a missing array element is appended as an assignment', function () {
    $out = Config_editor::apply(
        ['database.php' => "<?php\n\$databases = ['default' => ['host' => 'x']];\n"],
        [new Config_rule('databases.default.user', 'p7', 'database.php')],
    );
    assert_same(['database.php' => "<?php\n\$databases = ['default' => ['host' => 'x']];\n\$databases['default']['user'] = 'p7';\n"], $out);
});

test('a file the app lacks is created', function () use ($database_rules) {
    $out = Config_editor::apply(['config.php' => "<?php\n"], $database_rules('db'));
    assert_same(['database.php' => "<?php\n\$databases['default']['host'] = 'db';\n\$databases['default']['port'] = '3306';\n\$databases['default']['password'] = 'p\\'w';\n"], $out);
});

test('a file scope keeps a rule out of other files', function () {
    $files = [
        'config.php' => "<?php\ndefine('HOST', 'app-host');\n",
        'database.php' => "<?php\ndefine('HOST', 'localhost');\n",
    ];
    assert_same(['database.php' => "<?php\ndefine('HOST', 'db');\n"], Config_editor::apply($files, [new Config_rule('HOST', 'db', 'database.php')]));
});

test('an unscoped rule edits whichever files define the key, and only those', function () {
    $files = [
        'config.php' => "<?php\ndefine('ENV', 'dev');\n",
        'site_owner.php' => "<?php\n//Settings to do with the identification of the site owner\ndefine('WEBSITE_NAME', 'Trongate v2');\n",
        'custom_routing.php' => "<?php\n\$routes = [];\n",
    ];
    assert_same(
        ['site_owner.php' => "<?php\n//Settings to do with the identification of the site owner\ndefine('WEBSITE_NAME', 'Shop');\n"],
        Config_editor::apply($files, [new Config_rule('WEBSITE_NAME', 'Shop')]),
    );
});

test('rule keys and files are validated', function () {
    foreach (['$x', 'x..a', 'A-B', 'x.', '.x', '1x.a'] as $key) {
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

    assert_same(['databases', ['default', 'host']], (fn(Config_rule $r) => [$r->variable, $r->path])(new Config_rule('databases.default.host', 'x')));
});

// --- The platform's rules -------------------------------------------------------

$db = ['host' => 'shared-1.databases', 'port' => '3306', 'user' => 'tc_p7', 'password' => 'pw', 'database' => 'tc_p7'];

test('a v2 app gets BASE_URL, ENV and $databases set', function () use ($db) {
    $files = [
        'config.php' => "<?php\ndefine('BASE_URL', 'x');\ndefine('ENV', 'dev');\n",
        'database.php' => "<?php\n\$databases = ['default' => ['host' => '', 'port' => '', 'user' => '', 'password' => '', 'database' => '']];\n",
        'site_owner.php' => "<?php\ndefine('WEBSITE_NAME', 'Shop');\n",
    ];
    $out = Trongate_config::config_files($files, ['id' => 7, 'slug' => 'shop', 'trongate_env' => 'staging'], $db);

    assert_same(['config.php', 'database.php', 'trongate_email.php'], array_keys($out));
    assert_same("<?php\ndefine('BASE_URL', 'https://shop." . PLATFORM_APPS_DOMAIN . "/');\ndefine('ENV', 'staging');\n", $out['config.php']);
    assert_same("<?php\n\$databases = ['default' => ['host' => 'shared-1.databases', 'port' => '3306', 'user' => 'tc_p7', 'password' => 'pw', 'database' => 'tc_p7']];\n", $out['database.php']);
});

test('a v1 app gets the database constants', function () use ($db) {
    $files = [
        'config.php' => "<?php\ndefine('BASE_URL', 'x');\ndefine('ENV', 'dev');\n",
        'database.php' => "<?php\ndefine('HOST', '127.0.0.1');\ndefine('PORT', '3306');\ndefine('USER', 'root');\ndefine('PASSWORD', '');\ndefine('DATABASE', 'app');\n",
    ];
    $out = Trongate_config::config_files($files, ['id' => 7, 'slug' => 'shop', 'type' => 'trongate_v1'], $db);
    assert_same("<?php\ndefine('HOST', 'shared-1.databases');\ndefine('PORT', '3306');\ndefine('USER', 'tc_p7');\ndefine('PASSWORD', 'pw');\ndefine('DATABASE', 'tc_p7');\n", $out['database.php']);
});

test("a fresh trongate-framework app opens on 'welcome' instead of 'setup'", function () use ($db) {
    $files = ['config.php' => "<?php\ndefine('BASE_URL', '****');\ndefine('ENV', 'dev');\ndefine('DEFAULT_MODULE', 'setup');\ndefine('DEFAULT_METHOD', 'index');\n"];
    $out = Trongate_config::config_files($files, ['id' => 7, 'slug' => 'shop'], $db);
    assert_same(true, str_contains($out['config.php'], "define('DEFAULT_MODULE', 'welcome');"));
    assert_same(false, str_contains($out['config.php'], "'setup'"));
});

test("a default module the app chose is left alone", function () use ($db) {
    $sources = [
        "<?php\ndefine('DEFAULT_MODULE', 'shop');\n",
        "<?php\ndefine('DEFAULT_MODULE', getenv('DEFAULT_MODULE') ?: 'setup');\n",
        "<?php\ndefine('ENV', 'dev');\n",
    ];

    foreach ($sources as $source) {
        $out = Trongate_config::config_files(['config.php' => $source], ['id' => 7, 'slug' => 'shop'], $db);
        assert_same(substr_count($source, 'DEFAULT_MODULE'), substr_count($out['config.php'], 'DEFAULT_MODULE'));
        assert_same(false, str_contains($out['config.php'], "'welcome'"));
    }
});

test("'setup' is replaced in whichever config file defines it", function () use ($db) {
    $files = ['config.php' => "<?php\ndefine('ENV', 'dev');\n", 'site.php' => "<?php\ndefine('DEFAULT_MODULE', 'setup');\n"];
    $out = Trongate_config::config_files($files, ['id' => 7, 'slug' => 'shop'], $db);
    assert_same("<?php\ndefine('DEFAULT_MODULE', 'welcome');\n", $out['site.php']);
    assert_same(false, str_contains($out['config.php'], 'DEFAULT_MODULE'));
});

test('config_files passes on the Config_error', function () use ($config_error, $db) {
    $error = $config_error(fn() => Trongate_config::config_files(['database.php' => '<?php $databases = ['], ['id' => 7, 'slug' => 'shop'], $db));
    assert_same(['database.php', Config_error::FIX_FILE], [$error['file'], $error['action']]);
});

test('Manifests::app mounts the edited files from the app Secret', function () {
    $app = ['id' => 7, 'project_id' => 1, 'namespace' => 'project-1', 'slug' => 'shop', 'type' => 'trongate_v2', 'trongate_env' => 'prod'];
    $files = ['config.php' => "<?php\ndefine('ENV', 'dev');\ndefine('BASE_URL', 'x');\n", 'database.php' => "<?php\n\$databases = ['default' => []];\n", 'site_owner.php' => "<?php\n"];
    $deployment = ['id' => 3, 'image' => 'registry/p7:abc', 'config_files' => json_encode($files)];
    $manifests = Manifests::app($app, $deployment, ['host' => 'db', 'operator_name' => 'shared-1', 'password' => 'pw']);
    $by_kind = array_column($manifests, null, 'kind');

    assert_same("<?php\ndefine('ENV', 'prod');\ndefine('BASE_URL', 'https://shop." . PLATFORM_APPS_DOMAIN . "/');\n", $manifests[0]['stringData']['config.php']);
    assert_same(false, isset($manifests[0]['stringData']['site_owner.php']));

    $mounts = array_column($by_kind['Deployment']['spec']['template']['spec']['containers'][0]['volumeMounts'], null, 'mountPath');
    assert_same(['name' => 'config-files', 'mountPath' => Manifests::APP_DIR . '/config/config.php', 'subPath' => 'config.php', 'readOnly' => true], $mounts[Manifests::APP_DIR . '/config/config.php']);
    assert_same(true, isset($mounts[Manifests::APP_DIR . '/config/database.php']));
    assert_same(false, isset($mounts[Manifests::APP_DIR . '/config/site_owner.php']));
});

test('Manifests::app runs each process in its own Deployment that never sleeps', function () {
    $app = ['id' => 7, 'project_id' => 1, 'namespace' => 'project-1', 'slug' => 'shop', 'type' => 'trongate_v2', 'trongate_env' => 'prod'];
    $files = ['config.php' => "<?php\ndefine('ENV', 'dev');\ndefine('BASE_URL', 'x');\n", 'database.php' => "<?php\n\$databases = ['default' => []];\n"];
    $deployment = ['id' => 3, 'image' => 'registry/p7:abc', 'config_files' => json_encode($files)];
    $deployments = fn(array $app) => array_values(array_filter(
        Manifests::app($app, $deployment, ['host' => 'db', 'operator_name' => 'shared-1', 'password' => 'pw']),
        fn(array $m) => $m['kind'] === 'Deployment'
    ));

    assert_same(['p7-app'], array_map(fn($m) => $m['metadata']['name'], $deployments($app + ['processes' => []])));

    $all = $deployments($app + ['processes' => [
        ['name' => 'worker', 'command' => ' php bin/queue.php work --time-limit=3600 ', 'replicas' => 2],
        ['name' => 'scheduler', 'command' => 'php bin/schedule.php', 'replicas' => 1],
    ]]);
    [$web, $worker, $scheduler] = $all;
    assert_same(['p7-app', 'p7-worker', 'p7-scheduler'], array_map(fn($m) => $m['metadata']['name'], $all));
    assert_same(1, $scheduler['spec']['replicas']);
    assert_same(['sh', '-c', 'exec php bin/schedule.php'], $scheduler['spec']['template']['spec']['containers'][0]['command']);

    // Its own selector, so the app's Service never sends it traffic; no Sablier labels, its replicas
    assert_same(['app.kubernetes.io/name' => 'p7-worker'], $worker['spec']['selector']['matchLabels']);
    assert_same('p7-worker', $worker['spec']['template']['metadata']['labels']['app.kubernetes.io/name']);
    assert_same('7', $worker['metadata']['labels'][Manifests::APP_LABEL]);
    assert_same(false, isset($worker['metadata']['labels']['sablier.enable']));
    assert_same(true, isset($web['metadata']['labels']['sablier.enable']));
    assert_same('process', $worker['metadata']['labels']['app.kubernetes.io/component']);
    assert_same(2, $worker['spec']['replicas']);
    assert_same('Recreate', $worker['spec']['strategy']['type']);

    // The app's pod, running the command instead of FrankenPHP
    $container = $worker['spec']['template']['spec']['containers'][0];
    $web_container = $web['spec']['template']['spec']['containers'][0];
    assert_same(['sh', '-c', 'exec php bin/queue.php work --time-limit=3600'], $container['command']);
    assert_same($web_container['image'], $container['image']);
    assert_same($web_container['envFrom'], $container['envFrom']);
    assert_same($web_container['volumeMounts'], $container['volumeMounts']);
    assert_same([false, false, false], [isset($container['ports']), isset($container['startupProbe']), isset($container['securityContext']['capabilities']['add'])]);
    assert_same(['ALL'], $container['securityContext']['capabilities']['drop']);
    assert_same(Manifests::PROCESS_GRACE_SECONDS, $worker['spec']['template']['spec']['terminationGracePeriodSeconds']);

    // Removed by selector (Platform_task::apply, destroy_app), not as a fixed object
    assert_same(false, in_array(['apps/v1', 'Deployment', 'p7-worker'], Manifests::app_objects(7), true));
    assert_same(Manifests::APP_LABEL . '=7,app.kubernetes.io/component=process', Manifests::process_selector(7));
});

/** Run an edited trongate_email.php in a fresh PHP and return $config['trongate_email'] */
$run_email = function (string $source): array {
    $file = tempnam(sys_get_temp_dir(), 'cfg');
    file_put_contents($file, $source);
    $code = 'putenv("SMTP_USER=env@shop.test"); require $argv[1]; echo json_encode($config["trongate_email"]);';
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($file) . ' 2>&1');
    unlink($file);

    return json_decode(trim((string) $out), true) ?? throw new RuntimeException("Config did not run: {$out}");
};

$stock_email = <<<'PHP'
    <?php
    $config['trongate_email'] = [

        // Your SMTP server hostname
        'smtp_host' => '',

        // SMTP port (465 for SSL, 587 for STARTTLS)
        'smtp_port' => 465,

        // SMTP username (also used as the 'From' email address)
        'smtp_user' => '',

        // SMTP password
        'smtp_pass' => '',

        // Security: 'ssl', 'tls', or '' for no encryption
        'smtp_secure' => 'ssl',

        // Optional: Display name shown as the sender
        'smtp_from_name' => 'Trongate Support',
    ];
    PHP;

test('mail capture points the stock trongate_email.php at the inbox', function () use ($db, $run_email, $stock_email) {
    $out = Trongate_config::config_files(['trongate_email.php' => $stock_email], ['id' => 7, 'slug' => 'shop'], $db);

    assert_same([
        'smtp_host' => PLATFORM_MAIL_HOST,
        'smtp_port' => PLATFORM_MAIL_PORT,
        'smtp_user' => 'noreply@shop.' . PLATFORM_APPS_DOMAIN,
        'smtp_pass' => Mail_capture::password(7),
        'smtp_secure' => '',
        'smtp_from_name' => 'Trongate Support',
    ], $run_email($out['trongate_email.php']));
    assert_same(true, str_contains($out['trongate_email.php'], '// Optional: Display name shown as the sender'));
});

test("mail capture keeps the app's own From address", function () use ($db, $run_email, $stock_email) {
    $files = ['trongate_email.php' => str_replace("'smtp_user' => ''", "'smtp_user' => 'hello@shop.test'", $stock_email)];
    assert_same('hello@shop.test', $run_email(Trongate_config::config_files($files, ['id' => 7, 'slug' => 'shop'], $db)['trongate_email.php'])['smtp_user']);

    $files = ['trongate_email.php' => str_replace("'smtp_user' => ''", "'smtp_user' => getenv('SMTP_USER')", $stock_email)];
    assert_same('env@shop.test', $run_email(Trongate_config::config_files($files, ['id' => 7, 'slug' => 'shop'], $db)['trongate_email.php'])['smtp_user']);
});

test('mail capture creates trongate_email.php when the app has none', function () use ($db, $run_email) {
    $out = Trongate_config::config_files(['config.php' => "<?php\n"], ['id' => 7, 'slug' => 'shop'], $db);

    assert_same(Mail_capture::password(7), $run_email($out['trongate_email.php'])['smtp_pass']);
});

test('with mail capture off trongate_email.php is left alone', function () use ($db, $stock_email) {
    $out = Trongate_config::config_files(['trongate_email.php' => $stock_email], ['id' => 7, 'slug' => 'shop', 'captures_mail' => 0], $db);

    assert_same(false, isset($out['trongate_email.php']));
});

test('only apps with mail capture off may reach SMTP submission ports', function () {
    $files = ['config.php' => "<?php\n"];
    $deployment = ['id' => 3, 'image' => 'registry/p7:abc', 'config_files' => json_encode($files)];
    $pod_labels = function (array $app) use ($deployment): array {
        $by_kind = array_column(Manifests::app($app + ['id' => 7, 'project_id' => 1, 'namespace' => 'project-1', 'slug' => 'shop', 'type' => 'trongate_v2'], $deployment, ['host' => 'db', 'operator_name' => 'shared-1', 'password' => 'pw']), null, 'kind');

        return $by_kind['Deployment']['spec']['template']['metadata']['labels'];
    };

    assert_same(false, isset($pod_labels([])[Manifests::OWN_SMTP_LABEL]));
    assert_same('true', $pod_labels(['captures_mail' => 0])[Manifests::OWN_SMTP_LABEL]);

    $by_name = [];

    foreach (Manifests::tenant('project-1') as $manifest) {
        if ($manifest['kind'] === 'NetworkPolicy') {
            $by_name[$manifest['metadata']['name']] = $manifest['spec'];
        }
    }

    assert_same([80, 443], array_column($by_name['allow-internet']['egress'][0]['ports'], 'port'));
    assert_same([465, 587], array_column($by_name['allow-smtp-submission']['egress'][0]['ports'], 'port'));
    assert_same([Manifests::OWN_SMTP_LABEL => 'true'], $by_name['allow-smtp-submission']['podSelector']['matchLabels']);
});

finish_tests();
