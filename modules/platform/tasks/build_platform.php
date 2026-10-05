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

        foreach (['Dockerfile', 'Dockerfile.worker'] as $dockerfile) {
            if (!is_file("{$src}/{$dockerfile}")) {
                throw new RuntimeException("{$dockerfile} not found in {$repo}");
            }
        }

        $own_buildkitd = BUILDKIT_HOST === '';
        $buildkit_host = $own_buildkitd ? Platform_task::start_buildkitd($runtime, $log, $task_id, 'p') : BUILDKIT_HOST;

        // Web app first, worker last: Flux follows the worker image, so once its
        // tag exists the app's does too
        $images = ['trongate-cloud' => 'Dockerfile', 'trongate-cloud-worker' => 'Dockerfile.worker'];

        foreach ($images as $image => $dockerfile) {
            $ref = "{$registry}/{$image}";
            $log->info("Building image {$ref}:{$tag}");

            $script = implode("\n", [
                'set -eu',
                'buildctl --addr ' . $q($buildkit_host) . ' build'
                    . ' --progress=plain'
                    . ' --frontend=dockerfile.v0'
                    . ' --local context=' . $q($src)
                    . ' --local dockerfile=' . $q($src)
                    . ' --opt filename=' . $q($dockerfile)
                    . ' --opt platform=linux/amd64'
                    . ' --opt ' . $q("label:org.opencontainers.image.revision={$commit_sha}")
                    . ' --import-cache ' . $q("type=registry,ref={$ref}:buildcache")
                    . ' --export-cache ' . $q("type=registry,ref={$ref}:buildcache,mode=max,ignore-error=true")
                    . ' --output ' . $q("type=image,\"name={$ref}:{$tag},{$ref}:{$short_sha}\",push=true"),
            ]) . "\n";

            if ($runtime->shell($script, $log) !== 0) {
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
