<?php

/**
 * Deploy Task
 *
 * Builds an app's GitHub repository on PLATFORM_APP_BASE_IMAGE (FrankenPHP) with
 * a BuildKit daemon started for this build only, pushes it to PLATFORM_REGISTRY
 * and rolls it out to its project's namespace. GitHub is read with an
 * installation token of the trongate.cloud GitHub App.
 *
 * Payload: {deployment_id, app_id, project_id, commit_sha?}
 *
 * In scope (see Runtime): $payload, $task, $runtime.
 * Returns a Closure(Task_log): int. Tokens and passwords are never logged.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $db = $runtime->db();
    $project_id = (int) $payload['project_id'];
    $app_id = (int) $payload['app_id'];
    $deployment_id = (int) $payload['deployment_id'];

    $app = Platform_task::app($db, $project_id, $app_id);
    $deployment = Platform_task::deployment($db, $app_id, $deployment_id);

    $runtime->set_deployment_status($deployment_id, 'building');

    // --- Source --------------------------------------------------------------

    $provider = (string) ($app['source_provider'] ?? '');
    $source_url = rtrim((string) ($app['source_url'] ?? ''), '/');
    $branch = (string) ($app['source_branch'] ?: 'main');

    if ($provider !== 'github') {
        throw new RuntimeException("Unsupported source provider: {$provider}");
    }

    if (!preg_match('#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(\.git)?$#', $source_url, $match)) {
        throw new RuntimeException('App has no valid GitHub repository URL');
    }

    $repo = $match[1];

    try {
        $github = Platform_task::github_client($repo);
    } catch (Client_Error $e) {
        if ($e->getCode() === 404) {
            throw new RuntimeException("The trongate.cloud GitHub app cannot read {$repo}; install it on the repository on GitHub");
        }

        throw $e;
    }
    $commit_sha = strtolower((string) ($payload['commit_sha'] ?? ''));

    // Webhook pushes always build the branch head: a replayed (validly signed) old
    // push must not roll the app back to an older commit. Reconcile deploys stand
    // in for a missed push, so they follow the branch the same way.
    if (in_array($deployment['trigger_type'] ?? '', ['webhook', 'reconcile'], true) && $commit_sha !== '') {
        $head = $github->request('GET', "/repos/{$repo}/commits/" . rawurlencode($branch));
        $head_sha = strtolower((string) ($head['sha'] ?? ''));

        if ($head_sha !== '' && $head_sha !== $commit_sha) {
            $log->warn('Pushed commit ' . substr($commit_sha, 0, 7) . " is no longer the head of {$branch}; building the head instead");
            $commit_sha = $head_sha;
        }
    }

    // No commit: the branch head. A short SHA (queue_deploy accepts 7-40 chars): its full SHA.
    if (!preg_match('/^[0-9a-f]{40}$/', $commit_sha)) {
        $ref = $commit_sha !== '' ? $commit_sha : $branch;
        $commit = $github->request('GET', "/repos/{$repo}/commits/" . rawurlencode($ref));
        $commit_sha = strtolower((string) ($commit['sha'] ?? ''));
    }

    if (!preg_match('/^[0-9a-f]{40}$/', $commit_sha)) {
        throw new RuntimeException('Could not resolve the commit to deploy');
    }

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

        // Download in PHP so the token never appears in a command line or log
        $tarball = "{$work}/source.tar.gz";
        $handle = fopen($tarball, 'wb');
        $curl = curl_init($github->download_url($source_url, $commit_sha));
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_HTTPHEADER => $github->headers(),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_MAXFILESIZE_LARGE => 500 * 1024 * 1024,
        ]);
        $downloaded = curl_exec($curl);
        $download_error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($downloaded !== true) {
            throw new RuntimeException("Downloading the repository failed: {$download_error}");
        }

        $log->info('Downloaded source (' . round(filesize($tarball) / 1024) . ' KiB)');

        if ($runtime->shell('tar -xzf ' . $q($tarball) . ' --strip-components=1 -C ' . $q($src) . ' && rm -f ' . $q($tarball), $log) !== 0) {
            throw new RuntimeException('Extracting the repository failed');
        }

        // The tree is untrusted: PHP file calls below (and the build context) would
        // follow its symlinks, so refuse any that point outside the source dir.
        Platform_task::assert_no_escaping_symlinks($src);

        if (!is_file("{$src}/public/index.php")) {
            throw new RuntimeException('public/index.php not found; is this a Trongate app?');
        }

        if (!is_file("{$src}/config/config.php")) {
            throw new RuntimeException('config/config.php not found; the platform patches it at runtime');
        }

        // --- Dockerfile ------------------------------------------------------
        // Every Trongate app has the same shape, so the platform writes the
        // Dockerfile: the prebuilt base (docker/app-base) plus the app's extra
        // extensions, its composer packages and its files. Only the validated
        // extension names come from settings; nothing from the repository
        // becomes an instruction.

        $lines = ['FROM ' . PLATFORM_APP_BASE_IMAGE];

        // The base already has pdo_mysql, redis (Valkey sessions) and the common ones
        $extensions = [];

        foreach (preg_split('/[\s,]+/', strtolower((string) ($app['php_extensions'] ?? ''))) ?: [] as $extension) {
            if (preg_match('/^[a-z0-9_]{1,40}$/', $extension) && !in_array($extension, $extensions, true)) {
                $extensions[] = $extension;
            }
        }

        if ($extensions) {
            // install-php-extensions skips any the base already has
            $lines[] = 'RUN install-php-extensions ' . implode(' ', $extensions);
            $log->info('Extra PHP extensions: ' . implode(', ', $extensions));
        }

        // composer.json (and lock) first, so the packages layer stays cached
        // while only the app's own files change
        if (is_file("{$src}/composer.json")) {
            $lines[] = 'COPY composer.json ' . (is_file("{$src}/composer.lock") ? 'composer.lock ' : '') . './';
            // The autoloader comes after the app's files: a classmap entry
            // pointing at them fails while only composer.json is there
            $lines[] = 'RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction --no-progress';
        }

        $lines[] = 'COPY . ./';

        if (is_file("{$src}/composer.json")) {
            $lines[] = 'RUN composer dump-autoload --no-dev --optimize --no-scripts';
        }

        // A repository that ships a Caddyfile serves with it instead of the base's
        if (is_file("{$src}/Caddyfile")) {
            $lines[] = 'COPY Caddyfile /Caddyfile';
            $log->info('Using the repository Caddyfile');
        }

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

    $runtime->set_deployment_status($deployment_id, 'deploying', ['image' => $image]);
    $deployment['image'] = $image;

    // --- Roll out --------------------------------------------------------------

    $database = Platform_task::database_server($db, $app, $log);

    if (!Platform_task::roll_out($runtime, $log, $app, $deployment, $database)) {
        return 1;
    }

    $runtime->set_deployment_status($deployment_id, 'success');
    $log->info('Live at ' . Trongate_config::base_url($app));

    return 0;
};
