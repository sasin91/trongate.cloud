<?php

/**
 * Deploy Task
 *
 * Builds a project's GitHub repository with Railpack (FrankenPHP image) on
 * a BuildKit daemon started for this build only, pushes it to PLATFORM_REGISTRY and rolls it out to the team's
 * tenant namespace.
 *
 * Payload: {deployment_id, project_id, team_id, commit_sha?}
 *
 * In scope (see Runtime): $payload, $task, $runtime.
 * Returns a Closure(Task_log): int. Tokens and passwords are never logged.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $db = $runtime->db();
    $team_id = (int) $payload['team_id'];
    $project_id = (int) $payload['project_id'];
    $deployment_id = (int) $payload['deployment_id'];

    $project = Platform_task::project($db, $team_id, $project_id);
    $deployment = Platform_task::deployment($db, $project_id, $deployment_id);

    $runtime->set_deployment_status($deployment_id, 'building');

    // --- Source --------------------------------------------------------------

    $provider = (string) ($project['source_provider'] ?? '');
    $source_url = rtrim((string) ($project['source_url'] ?? ''), '/');
    $branch = (string) ($project['source_branch'] ?: 'main');

    if ($provider !== 'github') {
        throw new RuntimeException("Unsupported source provider: {$provider}");
    }

    if (!preg_match('#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(\.git)?$#', $source_url, $match)) {
        throw new RuntimeException('Project has no valid GitHub repository URL');
    }

    $repo = $match[1];
    $secret = $runtime->resolve_reference("secrets:team,{$team_id},github");
    $token = is_array($secret) ? (string) ($secret['token'] ?? '') : '';

    if ($token === '') {
        throw new RuntimeException('The team has no GitHub token; reconnect GitHub');
    }

    $github = new Github($token);
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

        // The tree is untrusted: PHP file calls below (and railpack prepare) would
        // follow its symlinks, so refuse any that point outside the source dir.
        Platform_task::assert_no_escaping_symlinks($src);

        if (!is_file("{$src}/public/index.php")) {
            throw new RuntimeException('public/index.php not found; is this a Trongate app?');
        }

        if (!is_file("{$src}/config/config.php")) {
            throw new RuntimeException('config/config.php not found; the platform patches it at runtime');
        }

        // --- Railpack config -------------------------------------------------
        // Trongate has no root index.php/composer.json, so force the PHP provider.
        // The repo's own railpack.json wins where it sets something.

        $config = [];
        $config_file = "{$src}/railpack.json";

        if (is_file($config_file)) {
            $config = json_decode((string) file_get_contents($config_file), true);

            if (!is_array($config)) {
                throw new RuntimeException('railpack.json in the repository is not valid JSON');
            }

            $log->info('Using the repository railpack.json');
        }

        $config['provider'] ??= 'php';
        $config['deploy']['variables']['CADDY_SERVER_EXTRA_DIRECTIVES']
            ??= 'header Content-Security-Policy "frame-ancestors \'self\'"';

        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Railpack's own Caddyfile logs every request at DEBUG; ours is the same at INFO.
        // Railpack uses a Caddyfile from the repo root, so a repo that ships one keeps it.
        if (!is_file("{$src}/Caddyfile")) {
            copy(__DIR__ . '/railpack/Caddyfile', "{$src}/Caddyfile");
        }

        // Railpack's PHP provider only reads RAILPACK_PHP_EXTENSIONS once composer.json
        // parses (it returns no extensions otherwise), and Trongate apps usually ship
        // none. An empty one makes the variable count; the composer step then just
        // writes an empty vendor/.
        if (!is_file("{$src}/composer.json")) {
            $composer = ['name' => 'trongate-cloud/app', 'type' => 'project', 'require' => new stdClass()];
            file_put_contents("{$src}/composer.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        // Trongate's database class uses PDO; project extras come from settings
        $extensions = ['pdo_mysql'];

        foreach (preg_split('/[\s,]+/', strtolower((string) ($project['php_extensions'] ?? ''))) ?: [] as $extension) {
            if (preg_match('/^[a-z0-9_]{1,40}$/', $extension) && !in_array($extension, $extensions, true)) {
                $extensions[] = $extension;
            }
        }

        // Railpack treats --env values as build secrets; buildctl must mount them too.
        // A bare "--env NAME" makes railpack read NAME from its own environment.
        // Invocation per https://railpack.com/platforms/running-railpack-in-production
        // (verified against railpack v0.40.1: frontend opts filename, build-arg:cache-key,
        // build-arg:secrets-hash; the plan is read from the "dockerfile" local).
        $build_env = [
            'RAILPACK_PHP_ROOT_DIR' => Manifests::APP_DIR . '/public',
            'RAILPACK_PHP_EXTENSIONS' => implode(',', $extensions),
        ];

        $log->info('PHP extensions: ' . $build_env['RAILPACK_PHP_EXTENSIONS']);

        // --- Build + push ----------------------------------------------------

        $registry = rtrim(PLATFORM_REGISTRY, '/');
        $image = "{$registry}/p{$project_id}:{$short_sha}-{$deployment_id}";
        $cache_ref = "{$registry}/p{$project_id}:buildcache";
        // The frontend must match the railpack CLI that wrote the plan (Dockerfile.worker sets both)
        $railpack_version = getenv('RAILPACK_VERSION') ?: '';
        $frontend = getenv('RAILPACK_FRONTEND')
            ?: 'ghcr.io/railwayapp/railpack-frontend' . ($railpack_version !== '' ? ':v' . ltrim($railpack_version, 'v') : ':latest');

        // A fixed BUILDKIT_HOST (local dev) or, in the cluster, a daemon for this build only
        $own_buildkitd = BUILDKIT_HOST === '';
        $buildkit_host = $own_buildkitd ? Platform_task::start_buildkitd($runtime, $log, $deployment_id) : BUILDKIT_HOST;

        $exports = [];
        $env_flags = [];
        $secret_flags = [];

        foreach ($build_env as $name => $value) {
            $exports[] = "export {$name}=" . $q($value);
            $env_flags[] = '--env ' . $name;
            $secret_flags[] = "--secret id={$name},env={$name}";
        }

        $script = implode("\n", [
            'set -eu',
            ...$exports,
            'railpack prepare ' . $q($src)
                . ' --plan-out ' . $q("{$plan_dir}/railpack-plan.json")
                . ' --info-out ' . $q("{$work}/railpack-info.json")
                . ' ' . implode(' ', $env_flags),
            'buildctl --addr ' . $q($buildkit_host) . ' build'
                . ' --progress=plain'
                . ' --frontend=gateway.v0'
                . ' --opt source=' . $q($frontend)
                . ' --local context=' . $q($src)
                . ' --local dockerfile=' . $q($plan_dir)
                . ' --opt build-arg:cache-key=' . $q("p{$project_id}")
                . ' --opt build-arg:secrets-hash=' . $q(hash('sha256', json_encode($build_env)))
                . ' ' . implode(' ', $secret_flags)
                . ' --import-cache ' . $q("type=registry,ref={$cache_ref}")
                . ' --export-cache ' . $q("type=registry,ref={$cache_ref},mode=max,ignore-error=true")
                . ' --output ' . $q("type=image,name={$image},push=true"),
        ]) . "\n";

        $log->info("Building image {$image}");

        if ($runtime->shell($script, $log) !== 0) {
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

    $database = Platform_task::database_server($db, $project, $log);
    Platform_task::apply($runtime, $log, $project, $deployment, $database);

    if (!Platform_task::wait_for_rollout($runtime, $log, $project)) {
        return 1;
    }

    $runtime->set_deployment_status($deployment_id, 'success');
    $log->info('Live at ' . Trongate_config::base_url($project));

    return 0;
};
