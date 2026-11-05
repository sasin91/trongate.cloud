<?php

/**
 * Import Git Task
 *
 * Moves a production app from GitHub to trongate.cloud git: every branch of
 * its GitHub repository, with its history, is copied into a bare repository
 * of its own (Git_repo, p{app_id}.git), and the app and its staging app
 * then build from there. A deploy of the production branch's head follows,
 * to show the new source works.
 *
 * Read with the GitHub App's installation token, or without one for a public
 * repository, like a deploy. A repository GitHub reports as over 1 GiB is
 * refused before anything is fetched (an invisible safety net: git bombs,
 * huge histories). Nothing on GitHub changes; its pushes no longer
 * reach the app (the webhook finds no app with that source_url).
 *
 * Payload: {project_id, app_id, customer_id?} (the production app).
 *
 * Queued by Platform_actions::queue_git_import (the Git tab's Move button).
 */

require_once __DIR__ . '/lib/Platform_task.php';
require_once __DIR__ . '/lib/source/Github_source.php';
require_once __DIR__ . '/../worker/Worker_actions.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $max_size_kib = 1024 * 1024;

    $db = $runtime->db();
    $project_id = (int) ($payload['project_id'] ?? 0);
    $app_id = (int) ($payload['app_id'] ?? 0);
    $customer_id = isset($payload['customer_id']) ? (int) $payload['customer_id'] : null;

    if ($project_id <= 0 || $app_id <= 0) {
        throw new InvalidArgumentException('import_git needs project_id and app_id');
    }

    $app = Platform_task::app($db, $project_id, $app_id);

    if ($app['source_provider'] === Git_repo::PROVIDER) {
        $log->info('Already on trongate.cloud git');
        return 0;
    }

    if ($app['environment'] !== 'production') {
        throw new RuntimeException('Move the production app; its staging app moves with it');
    }

    $github = Github_source::for_app($app);
    $name = $github->name();
    $size_kib = $github->size_kib();

    if ($size_kib > $max_size_kib) {
        throw new RuntimeException($name . ' is ' . round($size_kib / 1024) . ' MiB on GitHub, too big to move to trongate.cloud git');
    }

    $repo = Git_repo::for_app($app_id);
    $branch = (string) ($app['source_branch'] ?: 'main');

    $repo->create();
    $github->fetch_into($repo);

    $heads = $repo->branch_heads();
    $log->info('Copied ' . count($heads) . " branches of {$name} into trongate.cloud git ({$repo->name})");

    if (!isset($heads[$branch])) {
        throw new RuntimeException("{$name} has no branch {$branch}");
    }

    // The staging app builds from the same repository, its own branch
    $moved = Platform_task::execute(
        $db,
        "UPDATE app SET source_provider = ?, source_url = ?
         WHERE deleted_at IS NULL AND (id = ? OR (production_app_id = ? AND environment = 'staging'))",
        [Git_repo::PROVIDER, $repo->source_url(), $app_id, $app_id],
    );
    $log->info("{$moved} app(s) now build from trongate.cloud git");

    $deployment_id = (new Worker_actions($db))->queue_deploy($project_id, $app_id, $customer_id, 'manual', $heads[$branch]);
    $log->info("Queued deployment #{$deployment_id} of {$branch} (" . substr($heads[$branch], 0, 7) . ')');

    return 0;
};
