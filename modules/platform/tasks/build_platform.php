<?php

/**
 * Build Platform Task
 *
 * The platform deploying itself. Queued by the GitHub App webhook for a push
 * to PLATFORM_SOURCE_BRANCH of PLATFORM_SOURCE_REPOSITORY: builds Dockerfile
 * (web app) and Dockerfile.worker (this worker) for linux/amd64 on a BuildKit
 * daemon started for this build only, and pushes both to
 * PLATFORM_IMAGES_REGISTRY as
 *
 *   :main-{commit time, YYYYmmddHHMMSS UTC}-{short sha}   (Flux picks the newest)
 *   :{short sha}                                         (what bin/push-images.sh tags)
 *
 * Before those it builds docker/app-base, the base of every customer app, and
 * pushes it as PLATFORM_APP_BASE_IMAGE (and :{short sha}), then docker/laya as
 * PLATFORM_LAYA_IMAGE (and :{short sha}), the model apps can run next to them. Deploys use it as
 * soon as it is pushed. It is built with SOURCE_DATE_EPOCH=0 and no revision
 * label, so a commit that leaves it unchanged pushes the same digest and the
 * apps' build caches stay valid.
 *
 * Nothing is rolled out here. Flux image automation in the infra repository
 * (ImagePolicy on the worker image, which is pushed last, so both exist) commits
 * the new tag to the trongate-cloud Kustomization and Flux rolls out both
 * Deployments, this worker included, after this task has finished.
 *
 * Payload: {commit_sha?} (informational: the branch head is built, so a
 * replayed old push cannot roll the platform back).
 *
 * In scope (see Runtime): $payload, $task, $runtime.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($task, $runtime): int {
    $repo = PLATFORM_SOURCE_REPOSITORY;
    $branch = PLATFORM_SOURCE_BRANCH;
    $registry = rtrim(PLATFORM_IMAGES_REGISTRY, '/');
    $task_id = (int) $task['id'];

    if (!preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $repo)) {
        throw new RuntimeException('PLATFORM_SOURCE_REPOSITORY is not set to owner/name');
    }

    try {
        $github = Platform_task::github_client($repo);
    } catch (Client_Error $e) {
        if ($e->getCode() === 404) {
            throw new RuntimeException("The trongate.cloud GitHub app cannot read {$repo}; install it on the repository on GitHub");
        }

        throw $e;
    }

    $head = $github->request('GET', "/repos/{$repo}/commits/" . rawurlencode($branch));
    $commit_sha = strtolower((string) ($head['sha'] ?? ''));
    $committed_at = strtotime((string) ($head['commit']['committer']['date'] ?? ''));

    if (!preg_match('/^[0-9a-f]{40}$/', $commit_sha) || $committed_at === false) {
        throw new RuntimeException("Could not resolve the head of {$repo}@{$branch}");
    }

    $short_sha = substr($commit_sha, 0, 7);
    // Commit time, not build time: rebuilding an older commit never outranks a newer one
    $tag = 'main-' . gmdate('YmdHis', $committed_at) . "-{$short_sha}";
    $log->info("Building {$repo}@{$short_sha} ({$branch}) as :{$tag}");

    $work = rtrim(sys_get_temp_dir(), '/') . "/tc-build/platform-{$task_id}";
    $src = "{$work}/src";
    $q = fn(string $value) => Trongate_config::sh_quote($value);
    $own_buildkitd = false;

    try {
        if (is_dir($work)) {
            $runtime->shell('rm -rf ' . $q($work), $log);
        }

        if (!mkdir($src, 0700, true)) {
            throw new RuntimeException('Could not create the build directory');
        }

        // Download in PHP so the token never appears in a command line or log
        $tarball = "{$work}/source.tar.gz";
        $handle = fopen($tarball, 'wb');
        $curl = curl_init($github->download_url("https://github.com/{$repo}", $commit_sha));
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

        if ($runtime->shell('tar -xzf ' . $q($tarball) . ' --strip-components=1 -C ' . $q($src) . ' && rm -f ' . $q($tarball), $log) !== 0) {
            throw new RuntimeException('Extracting the repository failed');
        }

        foreach (['docker/app-base/Dockerfile', 'docker/laya/Dockerfile', 'Dockerfile', 'Dockerfile.worker'] as $dockerfile) {
            if (!is_file("{$src}/{$dockerfile}")) {
                throw new RuntimeException("{$dockerfile} not found in {$repo}");
            }
        }

        $own_buildkitd = BUILDKIT_HOST === '';
        $buildkit_host = $own_buildkitd ? Platform_task::start_buildkitd($runtime, $log, $task_id, 'p') : BUILDKIT_HOST;

        // The app base first (deploys and nothing else use it), then Laya
        // (apps that turn it on), the web app, the worker last: Flux follows
        // the worker image, so once its tag exists the others do too
        $laya_repository = Platform_task::image_repository(PLATFORM_LAYA_IMAGE);
        $scripts = [
            'app-base' => Platform_task::app_base_build(
                $buildkit_host,
                $src,
                [Platform_task::image_repository(PLATFORM_APP_BASE_IMAGE) . ":{$short_sha}"]
            ),
            // Its build cache keeps the torch and checkpoint layers, so an
            // unchanged Dockerfile costs a cache check and a push
            'laya' => Platform_task::buildctl_build(
                $buildkit_host,
                $src,
                $src,
                'docker/laya/Dockerfile',
                [PLATFORM_LAYA_IMAGE, "{$laya_repository}:{$short_sha}"],
                "{$laya_repository}:buildcache",
                ['build-arg:SOURCE_DATE_EPOCH=0']
            ),
        ];

        foreach (['trongate-cloud' => 'Dockerfile', 'trongate-cloud-worker' => 'Dockerfile.worker'] as $image => $dockerfile) {
            $ref = "{$registry}/{$image}";
            $scripts[$image] = Platform_task::buildctl_build(
                $buildkit_host,
                $src,
                $src,
                $dockerfile,
                ["{$ref}:{$tag}", "{$ref}:{$short_sha}"],
                "{$ref}:buildcache",
                ["label:org.opencontainers.image.revision={$commit_sha}"]
            );
        }

        foreach ($scripts as $image => $build) {
            $log->info("Building image {$image}");
            $script = 'set -eu' . "\n" . $build . "\n";

            if ($runtime->shell($script, $log) !== 0) {
                // Laya is optional for apps and heavy to build (torch and a
                // download from Hugging Face): a failure keeps the last pushed
                // image and must not hold back a platform release
                if ($image === 'laya') {
                    $log->warn('Building laya failed; apps keep the last ' . PLATFORM_LAYA_IMAGE);
                    continue;
                }

                if ($own_buildkitd) {
                    Platform_task::log_buildkitd($runtime, $log, $task_id, 'p');
                }

                $log->error("Building {$image} failed");
                return 1;
            }
        }
    } finally {
        if ($own_buildkitd) {
            Platform_task::stop_buildkitd($runtime, $log, $task_id, 'p');
        }

        if (is_dir($work)) {
            $runtime->shell('rm -rf ' . $q($work), $log);
        }
    }

    $log->info("Pushed :{$tag}; Flux rolls it out once its image policy sees the tag");

    return 0;
};
