<?php

require_once __DIR__ . '/../platform/manifests/Manifests.php';
require_once __DIR__ . '/../platform/mail/Mail_capture.php';

/**
 * "What happens" on a deployment page: what the platform does with an app,
 * step by step, filled in with the app's own settings. The config rows say
 * in words what Trongate_config::rules() sets (tests/Deploy_steps_test.php
 * keeps the keys in step), never the secrets themselves.
 *
 * Framework-free: the deployment page builds it, the test loads it.
 */
final class Deploy_steps {

    /** Where a deployment's status is in the steps; finished ones have no current step */
    const PHASES = ['pending' => 0, 'building' => 1, 'deploying' => 2, 'success' => 3];

    /**
     * @param array $app the app row with its app_runtime settings (find_member_app)
     * @param array $deployment the deployment row (find_member_deployment)
     * @return list<array{title: string, state: string, text: list<string>, config: list<array{file: string, setting: string, value: string}>}>
     *         state is done, current, waiting, or '' when the deployment's status says nothing about it
     */
    static function steps(array $app, array $deployment): array {
        $status = (string) ($deployment['status'] ?? '');

        return [
            self::step('Read your repository', 'building', $status, self::read_text($deployment)),
            self::step('Build the image', 'building', $status, self::build_text($app)),
            self::step('Set your config', 'deploying', $status, [
                'We edit copies of your config files; your repository is not changed. These values replace yours wherever you set them, also when you read them with getenv():',
            ], self::config_rows($app)),
            self::step('Start the new version', 'deploying', $status, self::start_text($app)),
            self::step('Check it before it takes traffic', 'deploying', $status, [
                'Your current version keeps serving until the new one answers ' . Runtime_settings::health_check_path($app) . ' with a 2xx or 3xx status (the Health Check Path in Settings).',
                'If it never does, or a test request after that fails, the previous deployment is put back.',
            ]),
            self::step('Later, without a build', '', $status, [
                'Step 3 runs again for this deployment when it is rolled back to, when your custom domain gets its certificate (BASE_URL moves to the domain), and when trongate.cloud changes what it sets.',
                'Changes in Settings take effect on the next deployment.',
            ]),
        ];
    }

    /**
     * One line for the closed card: the step the deployment is on (the
     * status covers several steps while deploying), or '' when it is not on one
     *
     * @param list<array{title: string, state: string}> $steps
     */
    static function now(array $steps): string {
        $current = [];

        foreach ($steps as $index => $step) {
            if ($step['state'] === 'current') {
                $current[] = $index;
            }
        }

        if ($current === []) {
            return '';
        }

        $first = $current[0] + 1;
        $last = end($current) + 1;

        if ($first === $last) {
            return "Now: {$first}. {$steps[$current[0]]['title']}";
        }

        return "Now: steps {$first} to {$last}";
    }

    /**
     * What the platform sets in the app's config/*.php, in the words the
     * page shows; secrets are named, never shown
     *
     * @return list<array{file: string, setting: string, value: string, keys: list<string>}>
     */
    static function config_rows(array $app): array {
        $rows = [
            self::row('config/*.php', 'BASE_URL', Trongate_config::base_url($app), ['BASE_URL']),
            self::row('config/*.php', 'ENV', Runtime_settings::trongate_env($app), ['ENV']),
        ];

        $default_module = Runtime_settings::default_module($app);

        if ($default_module !== '') {
            $rows[] = self::row('config/*.php', 'DEFAULT_MODULE', $default_module, ['DEFAULT_MODULE']);
        }

        $database_keys = ['host', 'port', 'user', 'password', 'database'];

        if (($app['type'] ?? 'trongate_v2') === 'trongate_v1') {
            $keys = array_map('strtoupper', $database_keys);
            $rows[] = self::row('database.php', implode(', ', $keys), 'Your app\'s own database (Database tab)', $keys);
        } else {
            $keys = array_map(fn(string $key) => "databases.default.{$key}", $database_keys);
            $rows[] = self::row('database.php', '$databases[\'default\']: ' . implode(', ', $database_keys), 'Your app\'s own database (Database tab)', $keys);
        }

        if (Runtime_settings::captures_mail($app)) {
            $mail_keys = ['smtp_host', 'smtp_port', 'smtp_pass', 'smtp_secure', 'smtp_user'];
            $keys = array_map(fn(string $key) => Mail_capture::CONFIG_PREFIX . $key, $mail_keys);
            $rows[] = self::row(Mail_capture::CONFIG_FILE, '$config[\'trongate_email\']: ' . implode(', ', $mail_keys), 'The trongate.cloud inbox (Mail tab). smtp_user only when yours is empty', $keys);
        }

        return $rows;
    }

    /**
     * @param list<string> $text
     * @param list<array{file: string, setting: string, value: string}> $config
     */
    private static function step(string $title, string $phase, string $status, array $text, array $config = []): array {
        return ['title' => $title, 'state' => self::state($phase, $status), 'text' => $text, 'config' => $config];
    }

    /**
     * done, current or waiting for a step in $phase while the deployment has
     * $status; '' for a failed or rolled back deployment (it is not known
     * which step it stopped at) and for steps outside the deploy
     */
    private static function state(string $phase, string $status): string {
        if ($phase === '' || !isset(self::PHASES[$status])) {
            return '';
        }

        if (self::PHASES[$phase] < self::PHASES[$status]) {
            return 'done';
        }

        if (self::PHASES[$phase] === self::PHASES[$status]) {
            return 'current';
        }

        return 'waiting';
    }

    private static function row(string $file, string $setting, string $value, array $keys): array {
        return ['file' => $file, 'setting' => $setting, 'value' => $value, 'keys' => $keys];
    }

    /**
     * @return list<string>
     */
    private static function read_text(array $deployment): array {
        $text = ['We download your branch from GitHub. It needs public/index.php and config/config.php.'];
        $files = json_decode((string) ($deployment['config_files'] ?? ''), true);

        if (is_array($files) && $files !== []) {
            $names = array_map(fn(string $file) => "config/{$file}", array_keys($files));
            $text[] = 'A copy of your config files is kept with this deployment, so it can be set up again without a build: ' . implode(', ', $names) . '.';
        } else {
            $text[] = 'A copy of your config/*.php files is kept with the deployment, so it can be set up again without a build.';
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private static function build_text(array $app): array {
        $text = ['Your code goes onto our PHP image with FrankenPHP and Caddy, and composer install runs.'];
        $extensions = Runtime_settings::php_extensions($app);

        if ($extensions !== []) {
            $text[] = 'Extra PHP extensions from Settings: ' . implode(', ', $extensions) . '.';
        }

        if (Runtime_settings::build_commands($app) !== '') {
            $text[] = 'Then your build commands from Settings run.';
        }

        $text[] = 'A Dockerfile in your repository is not used.';
        $text[] = 'A Caddyfile in your repository replaces ours. Ours tells PHP the visitor\'s IP address and that the request came over HTTPS, so yours needs to do the same, and listen on {$PORT}.';

        return $text;
    }

    /**
     * @return list<string>
     */
    private static function start_text(array $app): array {
        $text = [
            'The edited config files are put over yours, read-only.',
            'PHP sessions are kept outside the app, so visitors stay signed in across deploys and sleep.',
        ];
        $paths = Runtime_settings::persistent_paths($app);

        if ($paths !== []) {
            $text[] = 'Your persistent paths are writable and kept across deploys: ' . implode(', ', $paths) . '. On the first deploy they start with the files from your repository.';
            $text[] = 'Everything else in your app is read-only. /tmp is writable, and empty on every start.';
        } else {
            $text[] = 'Your app is read-only. To keep uploads, add their folder under Persistent paths in Settings. /tmp is writable, and empty on every start.';
        }

        return $text;
    }
}
