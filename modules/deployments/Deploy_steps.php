<?php

require_once __DIR__ . '/../platform/manifests/Manifests.php';
require_once __DIR__ . '/../platform/mail/Mail_capture.php';

/**
 * The deployment page's timeline: the steps of a deploy, how far this
 * deployment got (the worker logs a 'step' line as it enters each one,
 * Task_log::step), its log lines under the step they belong to, and what
 * the platform does in each step, filled in with the app's settings. The
 * config rows say in words what Trongate_config::rules() sets
 * (tests/Deploy_steps_test.php keeps the keys in step), never the secrets.
 *
 * Framework-free: the deployment page builds it, the test loads it.
 */
final class Deploy_steps {

    /** The steps in order; the keys are what the worker logs (deploy.php, Platform_task::roll_out) */
    const KEYS = ['source', 'build', 'config', 'start', 'check', 'restore'];

    const TITLES = [
        'source' => 'Read your repository',
        'build' => 'Build the image',
        'config' => 'Set your config',
        'start' => 'Start the new version',
        'check' => 'Check it before it takes traffic',
        'restore' => 'Put the previous version back',
    ];

    /** Only shown once the deployment reaches it */
    const OPTIONAL = ['restore'];

    const RUNNING = ['pending', 'building', 'deploying'];

    const STATE_LABELS = ['done' => 'Done', 'current' => 'Running', 'failed' => 'Failed', 'skipped' => 'Skipped', 'waiting' => '', 'hidden' => '', '' => ''];

    /**
     * @param array $app the app row with its app_runtime settings (find_member_app)
     * @param array $deployment the deployment row (find_member_deployment)
     * @param list<object> $marks the deployment's 'step' log lines, oldest first (id, message, created_at)
     * @return list<array{key: string, number: int, title: string, state: string, label: string, time: string, text: list<string>, config: list<array{file: string, setting: string, value: string}>}>
     */
    static function steps(array $app, array $deployment, array $marks): array {
        $status = (string) ($deployment['status'] ?? '');
        $reached = self::reached($marks);
        $texts = [
            'source' => self::source_text($deployment),
            'build' => self::build_text($app),
            'config' => ['We edit copies of your config files; your repository is not changed. These values replace yours wherever you set them, also when you read them with getenv():'],
            'start' => self::start_text($app),
            'check' => [
                'Your current version keeps serving until the new one answers ' . Runtime_settings::health_check_path($app) . ' with a 2xx or 3xx status (the Health Check Path in Settings), and a test request to it passes.',
            ],
            'restore' => ['The new version did not pass, so the deployment that was live before is set up again and keeps serving.'],
        ];
        $steps = [];

        foreach (self::KEYS as $index => $key) {
            $state = self::state($key, $reached, $status);

            $steps[] = [
                'key' => $key,
                'number' => $index + 1,
                'title' => self::TITLES[$key],
                'state' => $state,
                'label' => self::STATE_LABELS[$state],
                'time' => isset($reached[$key]) ? date('H:i:s', strtotime($reached[$key])) : '',
                'text' => $texts[$key],
                'config' => $key === 'config' ? self::config_rows($app) : [],
            ];
        }

        return $steps;
    }

    /**
     * Whether the deployment's log has no steps to put its lines under: it
     * finished before the worker logged them
     *
     * @param list<object> $marks
     */
    static function is_unsorted(array $deployment, array $marks): bool {
        return $marks === [] && !in_array((string) ($deployment['status'] ?? ''), self::RUNNING, true);
    }

    /**
     * The log lines under the step they were written in: each line belongs
     * to the last 'step' line before it, lines before any to the first step.
     * The 'step' lines themselves are left out.
     *
     * @param list<object> $logs log lines, oldest first (id, level, message, created_at)
     * @param list<object> $marks the deployment's 'step' lines, oldest first
     * @return array<string, list<object>> every step key, with its lines
     */
    static function group_logs(array $logs, array $marks): array {
        $groups = array_fill_keys(self::KEYS, []);

        foreach ($logs as $line) {
            if ($line->level === 'step') {
                continue;
            }

            $groups[self::step_of((int) $line->id, $marks)][] = $line;
        }

        return $groups;
    }

    /**
     * One line for the timeline's heading: the step the deployment is on,
     * or '' when it is not on one
     *
     * @param list<array{number: int, title: string, state: string}> $steps
     */
    static function now(array $steps): string {
        foreach ($steps as $step) {
            if ($step['state'] === 'current') {
                return "Now: {$step['number']}. {$step['title']}";
            }
        }

        return '';
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
     * The steps the deployment's log marks it entering, in that order, with
     * when it did: key => created_at
     *
     * @param list<object> $marks
     * @return array<string, string>
     */
    private static function reached(array $marks): array {
        $reached = [];

        foreach ($marks as $mark) {
            if (in_array($mark->message, self::KEYS, true)) {
                $reached[$mark->message] = (string) $mark->created_at;
            }
        }

        return $reached;
    }

    /**
     * done, current, failed, skipped (a later step was reached without it,
     * as in a rollback, which builds nothing), waiting, hidden (an optional
     * step not reached), or '' when the log has no steps
     *
     * @param array<string, string> $reached
     */
    private static function state(string $key, array $reached, string $status): string {
        $is_running = in_array($status, self::RUNNING, true);

        if ($reached === [] && !$is_running) {
            return in_array($key, self::OPTIONAL, true) ? 'hidden' : '';
        }

        if (!isset($reached[$key])) {
            return self::state_not_reached($key, $reached);
        }

        $order = array_keys($reached);
        $position = (int) array_search($key, $order, true);
        $is_latest = $position === count($order) - 1;

        if ($is_latest && $is_running) {
            return 'current';
        }

        if ($is_latest && $status === 'failed' && $key !== 'restore') {
            return 'failed';
        }

        // The step that did not pass is the one before the restore
        if (($order[$position + 1] ?? '') === 'restore') {
            return 'failed';
        }

        return 'done';
    }

    /**
     * @param array<string, string> $reached
     */
    private static function state_not_reached(string $key, array $reached): string {
        if (in_array($key, self::OPTIONAL, true)) {
            return 'hidden';
        }

        $index = array_search($key, self::KEYS, true);

        foreach (array_keys($reached) as $reached_key) {
            if (array_search($reached_key, self::KEYS, true) > $index && !in_array($reached_key, self::OPTIONAL, true)) {
                return 'skipped';
            }
        }

        return 'waiting';
    }

    /**
     * The key of the last 'step' line before the log line $id
     *
     * @param list<object> $marks
     */
    private static function step_of(int $id, array $marks): string {
        $key = self::KEYS[0];

        foreach ($marks as $mark) {
            if ((int) $mark->id > $id) {
                break;
            }

            if (in_array($mark->message, self::KEYS, true)) {
                $key = $mark->message;
            }
        }

        return $key;
    }

    private static function row(string $file, string $setting, string $value, array $keys): array {
        return ['file' => $file, 'setting' => $setting, 'value' => $value, 'keys' => $keys];
    }

    /**
     * @return list<string>
     */
    private static function source_text(array $deployment): array {
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
