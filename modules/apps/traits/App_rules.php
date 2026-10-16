<?php

require_once APPPATH . 'modules/platform/manifests/App_env.php';
require_once APPPATH . 'modules/platform/manifests/Trongate_config.php';
require_once APPPATH . 'modules/platform/manifests/Build_commands.php';

/**
 * Rules for an app's settings, shared by the forms (App_form, Apps) and the
 * MCP tools: validation callbacks (bool|string, so Trongate Validation can
 * call them and other callers can read the message) and the parsers behind them.
 *
 * The using controller's model must use App_records (domain_taken).
 *
 * @property-read Secrets $secrets
 */
trait App_rules {

    /** app.type values */
    const APP_TYPES = [
        'trongate_v2' => 'Trongate v2',
        'trongate_v1' => 'Trongate v1',
    ];

    const MAX_PERSISTENT_PATHS = 20;

    /** Top-level directories the platform mounts or owns; never persistent */
    const RESERVED_PATHS = ['config', 'engine'];

    /** The app whose settings are being saved, so its own domain does not count as taken */
    private int $editing_app_id = 0;

    /**
     * The app's environment variables, decrypted (App_env)
     *
     * @return array<string, string>
     */
    function _app_env(object $app): array {
        $this->module('secrets');

        return $this->secrets->exists(App_env::MODULE, (int) $app->id, App_env::SERVICE)
            ? $this->secrets->get(App_env::MODULE, (int) $app->id, App_env::SERVICE)
            : [];
    }

    /**
     * Hosts (with all their subdomains) an app may never claim: the control
     * panel (BASE_URL) and the image registry (PLATFORM_REGISTRY).
     *
     * @return list<string>
     */
    function _platform_hosts(): array {
        $hosts = [
            (string) parse_url(BASE_URL, PHP_URL_HOST),
            (string) parse_url('https://' . PLATFORM_REGISTRY, PHP_URL_HOST),
        ];

        return array_values(array_unique(array_filter(array_map('strtolower', $hosts))));
    }

    function _normalise_domain(string $domain): ?string {
        $domain = rtrim(strtolower(trim($domain)), '.');

        return $domain === '' ? null : $domain;
    }

    /**
     * @return list<string> Normalised paths (no leading "./", no trailing "/", unique)
     */
    function _parse_persistent_paths(string $value): array {
        $paths = [];

        foreach (preg_split('/\s+/', trim($value)) ?: [] as $path) {
            $path = rtrim(preg_replace('#^(\./)+#', '', $path) ?? '', '/');

            if ($path !== '') {
                $paths[$path] = $path;
            }
        }

        return array_values($paths);
    }

    /**
     * @return list<string> Lowercase extension names
     */
    function _parse_php_extensions(string $value): array {
        $names = preg_split('/[\s,]+/', strtolower(trim($value))) ?: [];

        return array_values(array_unique(array_filter($names, fn(string $name) => $name !== '')));
    }

    // --- Validation callbacks ---

    function _validate_repository(string $repository): bool|string {
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository)
            || preg_match('#(^|/)\.{1,2}(/|$)#', $repository)) {
            return 'Please choose a repository.';
        }

        return true;
    }

    function _validate_branch(string $branch): bool|string {
        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $branch) || str_contains($branch, '..') || str_starts_with($branch, '-')) {
            return 'Invalid branch name.';
        }

        return true;
    }

    function _validate_app_type(string $type): bool|string {
        if (!isset(self::APP_TYPES[$type])) {
            return 'Invalid app type.';
        }

        return true;
    }

    /**
     * Optional custom domain: a valid hostname, not a platform host and not verified by another app.
     * The app being edited ($editing_app_id) is ignored.
     */
    function _validate_domain(string $domain): bool|string {
        $domain = $this->_normalise_domain($domain);

        if ($domain === null) {
            return true;
        }

        $label = '[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?';
        if (!preg_match("/^({$label}\\.)+[a-z]{2,63}$/", $domain)) {
            return 'Please enter a valid domain, e.g. app.example.com.';
        }

        $apps_domain = strtolower(PLATFORM_APPS_DOMAIN);
        if ($domain === $apps_domain || str_ends_with($domain, '.' . $apps_domain)) {
            return 'Your app already gets a free ' . $apps_domain . ' address; enter your own domain here.';
        }

        foreach ($this->_platform_hosts() as $host) {
            if ($domain === $host || str_ends_with($domain, '.' . $host)) {
                return 'This domain belongs to the platform; enter your own domain.';
            }
        }

        if ($this->model->domain_taken($domain, $this->editing_app_id > 0 ? $this->editing_app_id : null)) {
            return 'This domain is already used by another app.';
        }

        return true;
    }

    /**
     * One relative path per line (the validator sees newlines collapsed to spaces).
     */
    function _validate_persistent_paths(string $value): bool|string {
        $paths = $this->_parse_persistent_paths($value);

        if (count($paths) > self::MAX_PERSISTENT_PATHS) {
            return 'At most ' . self::MAX_PERSISTENT_PATHS . ' persistent paths are allowed.';
        }

        foreach ($paths as $path) {
            if (!preg_match('#^[A-Za-z0-9._/-]+$#', $path) || strlen($path) > 200) {
                return "\"{$path}\" may only contain letters, numbers, dots, dashes, underscores and slashes.";
            }

            if (str_starts_with($path, '/')) {
                return "\"{$path}\" must be relative to your app's root, e.g. modules/blog/uploads.";
            }

            $segments = explode('/', $path);
            if (in_array('..', $segments, true) || in_array('.', $segments, true) || in_array('', $segments, true)) {
                return "\"{$path}\" may not contain \"..\", \".\" or empty segments.";
            }

            if (in_array($segments[0], self::RESERVED_PATHS, true)) {
                return "\"{$path}\" is managed by the platform and cannot be persistent.";
            }
        }

        return true;
    }

    /**
     * Shell lines run in the image build (Build_commands)
     */
    function _validate_build_commands(string $value): bool|string {
        return Build_commands::error($value) ?? true;
    }

    function _validate_php_extensions(string $value): bool|string {
        foreach ($this->_parse_php_extensions($value) as $name) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) {
                return "\"{$name}\" is not a valid PHP extension name.";
            }
        }

        return true;
    }

    /**
     * The Processes rows of the settings form (process_name[], process_command[],
     * process_replicas[]); rows left empty are dropped
     *
     * @return list<array{name: string, command: string, replicas: int}>
     */
    function _processes_from_post(): array {
        $names = (array) post('process_name');
        $commands = (array) post('process_command');
        $replicas = (array) post('process_replicas');
        $processes = [];

        foreach ($names as $i => $name) {
            $name = strtolower(trim((string) $name));
            $command = trim((string) ($commands[$i] ?? ''));

            if ($name === '' && $command === '') {
                continue;
            }

            $count = trim((string) ($replicas[$i] ?? ''));
            $processes[] = ['name' => $name, 'command' => $command, 'replicas' => $count === '' ? 1 : (int) $count];
        }

        return $processes;
    }

    /**
     * Settings form callback (on a hidden field): the Processes rows are valid
     */
    function _validate_processes(): bool|string {
        return $this->_processes_error($this->_processes_from_post()) ?? true;
    }

    /**
     * What is wrong with a list of processes, or null
     *
     * @param list<array{name: string, command: string, replicas: int}> $processes
     */
    function _processes_error(array $processes): ?string {
        if (count($processes) > Manifests::MAX_PROCESSES) {
            return 'An app can have at most ' . Manifests::MAX_PROCESSES . ' processes.';
        }

        $seen = [];

        foreach ($processes as $process) {
            $name = $process['name'];

            if (!preg_match(Manifests::PROCESS_NAME_PATTERN, $name) || $name === 'app') {
                return "\"{$name}\" can't be a process name: use lowercase letters, digits and dashes (up to 20), not \"app\".";
            }

            if (isset($seen[$name])) {
                return "There are two processes named {$name}.";
            }
            $seen[$name] = true;

            // One command, run with exec (Manifests::_process_deployment)
            if ($process['command'] === '' || mb_strlen($process['command']) > 500) {
                return "Give {$name} a command (at most 500 characters).";
            }

            if (preg_match('/[\r\n;&|`]|\$\(/', $process['command'])) {
                return "{$name}'s command is one command on one line, without ; & | ` or $( ).";
            }

            if ($process['replicas'] < 1 || $process['replicas'] > Manifests::MAX_PROCESS_REPLICAS) {
                return "{$name} runs 1 to " . Manifests::MAX_PROCESS_REPLICAS . ' copies.';
            }
        }

        return null;
    }

    /**
     * Trongate's ENV constant for the app (Runtime_settings::TRONGATE_ENV_PATTERN)
     */
    function _validate_trongate_env(string $value): bool|string {
        if (!preg_match(Runtime_settings::TRONGATE_ENV_PATTERN, trim($value))) {
            return 'The environment may only contain letters, numbers, dashes and underscores (at most 20).';
        }

        return true;
    }

    /**
     * The health check path as entered; empty is the home page
     */
    function _parse_health_check_path(string $value): string {
        $path = trim($value);

        return $path === '' ? '/' : $path;
    }

    /**
     * A health check path the rollout gate can request (Runtime_settings::HEALTH_CHECK_PATH_PATTERN)
     */
    function _validate_health_check_path(string $value): bool|string {
        if (!preg_match(Runtime_settings::HEALTH_CHECK_PATH_PATTERN, $this->_parse_health_check_path($value))) {
            return 'The health check path must start with / and may only contain letters, numbers and . _ ~ - / (at most 200, no query string).';
        }

        return true;
    }

    /**
     * A variable name that may be set on the app being edited ($editing_app_id):
     * not reserved, and within App_env::MAX_VARIABLES.
     */
    function _validate_env_name(string $value): bool|string {
        $name = trim($value);
        $error = App_env::name_error($name);

        if ($error !== null) {
            return $error;
        }

        $env = $this->_app_env((object) ['id' => $this->editing_app_id]);

        if (!array_key_exists($name, $env) && count($env) >= App_env::MAX_VARIABLES) {
            return 'An app can have at most ' . App_env::MAX_VARIABLES . ' environment variables.';
        }

        return true;
    }
}
