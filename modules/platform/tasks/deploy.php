<?php

/**
 * Deploy Task
 *
 * Builds an app's repository on PLATFORM_APP_BASE_IMAGE (FrankenPHP) with
 * a BuildKit daemon started for this build only, pushes it to PLATFORM_REGISTRY
 * and rolls it out to its project's namespace. GitHub is read with an
 * installation token of the trongate.cloud GitHub App, or without a token
 * when the App is not installed on a public repository (a pasted link);
 * trongate.cloud git straight from its bare repository (Deploy_sources).
 *
 * Payload: {deployment_id, app_id, project_id, commit_sha?}
 *
 * In scope (see Runtime): $payload, $task, $runtime.
 * Returns a Closure(Task_log): int. Tokens and passwords are never logged.
 */

require_once __DIR__ . '/lib/Platform_task.php';
require_once __DIR__ . '/lib/source/Deploy_sources.php';
require_once __DIR__ . '/../manifests/Config_editor.php';
require_once __DIR__ . '/../manifests/Build_commands.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $db = $runtime->db();
    $project_id = (int) $payload['project_id'];
    $app_id = (int) $payload['app_id'];
    $deployment_id = (int) $payload['deployment_id'];

    $app = Platform_task::app($db, $project_id, $app_id);
    $deployment = Platform_task::deployment($db, $app_id, $deployment_id);

    $runtime->set_deployment_status($deployment_id, 'building');

    // --- Source --------------------------------------------------------------

    $log->step('source');

    $branch = (string) ($app['source_branch'] ?: 'main');
    $source = Deploy_sources::for_app($app);
    $repo = $source->name();
    $commit_sha = $source->commit($branch, $deployment, (string) ($payload['commit_sha'] ?? ''), $log);

    $short_sha = substr($commit_sha, 0, 7);
    $runtime->set_deployment_status($deployment_id, 'building', ['commit_sha' => $commit_sha]);
    $log->info("Building {$repo}@{$short_sha} ({$branch})");

    $work = rtrim(sys_get_temp_dir(), '/') . "/tc-build/{$deployment_id}";
    $src = "{$work}/src";
    $plan_dir = "{$work}/plan";
    $q = fn(string $value) => Trongate_config::sh_quote($value);
    $own_buildkitd = false;

    try {
        if (is_dir($work)) {
            $runtime->shell('rm -rf ' . $q($work), $log);
        }

        if (!mkdir($src, 0700, true) || !mkdir($plan_dir, 0700, true)) {
            throw new RuntimeException('Could not create the build directory');
        }

        $source->extract($runtime, $log, $commit_sha, $work, $src);

        // The tree is untrusted: PHP file calls below (and the build context) would
        // follow its symlinks, so refuse any that point outside the source dir.
        Platform_task::assert_no_escaping_symlinks($src);

        if (!is_file("{$src}/public/index.php")) {
            throw new RuntimeException('public/index.php not found; is this a Trongate app?');
        }

        if (!is_file("{$src}/config/config.php")) {
            throw new RuntimeException('config/config.php not found; the platform patches it at runtime');
        }

        // Kept with the deployment: every apply (this one, a rollback, a new
        // domain) edits them and mounts the edited ones over the image's
        // (Manifests::app). A file that is not valid PHP fails the deploy
        // here, before the build, with the reason.
        $config_files = [];

        foreach (glob("{$src}/config/*.php") ?: [] as $path) {
            if (count($config_files) > Config_editor::MAX_FILES) {
                break; // enough for check() to refuse
            }

            // Only names a Secret key can carry; check() refuses the rest anyway
            if (is_file($path) && !is_link($path) && preg_match(Config_rule::FILE_PATTERN, basename($path))) {
                // Read at most one byte over the limit; check() refuses the rest
                $config_files[basename($path)] = (string) file_get_contents($path, false, null, 0, Config_editor::MAX_FILE_BYTES + 1);
            }
        }

        Config_editor::check($config_files);

        $config_json = json_encode($config_files, JSON_THROW_ON_ERROR);

        // --- Dockerfile ------------------------------------------------------

        $log->step('build');

        // Every Trongate app has the same shape, so the platform writes the
        // Dockerfile: the prebuilt base (docker/app-base) plus the app's extra
        // extensions, its composer packages, its files and its build commands
        // (Build_commands::dockerfile). Only validated settings become
        // instructions; nothing from the repository does.

        // The base already has pdo_mysql, redis (Valkey sessions) and the common ones
        $extensions = Runtime_settings::php_extensions($app);

        $extension_lines = [];

        if ($extensions) {
            // install-php-extensions skips any the base already has
            $extension_lines[] = 'RUN install-php-extensions ' . implode(' ', $extensions);
            $log->info('Extra PHP extensions: ' . implode(', ', $extensions));
        }

        $app_lines = [];

        // composer.json (and lock) first, so the packages layer stays cached
        // while only the app's own files change
        if (is_file("{$src}/composer.json")) {
            $app_lines[] = 'COPY composer.json ' . (is_file("{$src}/composer.lock") ? 'composer.lock ' : '') . './';
            // The autoloader comes after the app's files: a classmap entry
            // pointing at them fails while only composer.json is there
            $app_lines[] = 'RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction --no-progress';
        }

        $app_lines[] = 'COPY . ./';

        if (is_file("{$src}/composer.json")) {
            $app_lines[] = 'RUN composer dump-autoload --no-dev --optimize --no-scripts';
        }

        $final_lines = [];

        // A repository that ships a Caddyfile serves with it instead of the base's
        if (is_file("{$src}/Caddyfile")) {
            $final_lines[] = 'COPY Caddyfile /Caddyfile';
            $log->info('Using the repository Caddyfile');
        }

        $build_commands = Runtime_settings::build_commands($app);

        if ($build_commands !== '') {
            $log->info("Build commands:\n{$build_commands}");
        }

        $lines = Build_commands::dockerfile(PLATFORM_APP_BASE_IMAGE, $extension_lines, $app_lines, $final_lines, $build_commands);
        file_put_contents("{$plan_dir}/Dockerfile", implode("\n", $lines) . "\n");

        // --- Build + push ----------------------------------------------------

        $registry = rtrim(PLATFORM_REGISTRY, '/');
        $image = "{$registry}/p{$app_id}:{$short_sha}-{$deployment_id}";
        $cache_ref = "{$registry}/p{$app_id}:buildcache";

        // A fixed BUILDKIT_HOST (local dev) or, in the cluster, a daemon for this build only
        $own_buildkitd = BUILDKIT_HOST === '';
        $buildkit_host = $own_buildkitd ? Platform_task::start_buildkitd($runtime, $log, $deployment_id) : BUILDKIT_HOST;

        // The Dockerfile comes from the plan dir, so one in the repository is ignored
        $script = 'set -eu' . "\n"
            . Platform_task::buildctl_build($buildkit_host, $src, $plan_dir, 'Dockerfile', [$image], $cache_ref) . "\n";

        $log->info("Building image {$image}");

        $built = Platform_task::ensure_app_base($runtime, $log, $buildkit_host, $work)
            && $runtime->shell($script, $log) === 0;

        if (!$built) {
            if ($own_buildkitd) {
                Platform_task::log_buildkitd($runtime, $log, $deployment_id);
            }

            $log->error('Build failed');
            return 1;
        }
    } finally {
        if ($own_buildkitd) {
            Platform_task::stop_buildkitd($runtime, $log, $deployment_id);
        }

        if (is_dir($work)) {
            $runtime->shell('rm -rf ' . $q($work), $log);
        }
    }

    $runtime->set_deployment_status($deployment_id, 'deploying', ['image' => $image, 'config_files' => $config_json]);
    $deployment['image'] = $image;
    $deployment['config_files'] = $config_json;

    // --- Roll out --------------------------------------------------------------

    $database = Platform_task::database_server($db, $app, $log);

    if (!Platform_task::roll_out($runtime, $log, $app, $deployment, $database)) {
        return 1;
    }

    $runtime->set_deployment_status($deployment_id, 'success');
    $log->info('Live at ' . Trongate_config::base_url($app));

    return 0;
};
