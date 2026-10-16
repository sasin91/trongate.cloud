<?php

/**
 * Split Staging Task
 *
 * Gives a production app a staging app once its custom domain is live with
 * HTTPS: {slug}.PLATFORM_APPS_DOMAIN, where the app was tried out until now,
 * moves to the staging app, and production answers only on its domain.
 *
 * The staging app is a copy of the production app with the same slug
 * (app.environment 'staging', app.production_app_id): its settings and
 * environment variables are copied once and are separate after that. It
 * deploys pushes to STAGING_BRANCH, gets its own database, runs none of
 * production's processes, sleeps when idle and captures mail. Nothing is
 * deployed here: it goes live on the branch's first push or a Deploy press.
 *
 * Production keeps its database, settings, deployments and domain. It is
 * re-applied with its current deployment so its route drops the {slug} host
 * (app.serves_slug_host 0, Manifests::serves_slug_host).
 *
 * Payload: {project_id, app_id} (the production app).
 *
 * Queued by recheck_domains when it secures a production app's domain, and by
 * Platform_actions::queue_staging_split (the app's Add staging button). Does
 * nothing for an app that already has a staging app or whose domain is not
 * secured yet.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    // The branch a new staging app deploys from; its Settings change it
    $staging_branch = 'staging';

    $db = $runtime->db();
    $project_id = (int) ($payload['project_id'] ?? 0);
    $app_id = (int) ($payload['app_id'] ?? 0);

    if ($project_id <= 0 || $app_id <= 0) {
        throw new InvalidArgumentException('split_staging needs project_id and app_id');
    }

    $app = Platform_task::app($db, $project_id, $app_id);

    if ($app['environment'] !== 'production') {
        $log->info('A staging app has no staging app of its own');
        return 0;
    }

    if (Manifests::secured_domains($app) === []) {
        $log->info('Staging is set up once the custom domain is live with HTTPS');
        return 0;
    }

    $existing = Platform_task::row(
        $db,
        "SELECT id FROM app WHERE production_app_id = ? AND environment = 'staging' AND deleted_at IS NULL",
        [$app_id],
    );

    if ($existing !== null) {
        $log->info("Already has staging app #{$existing['id']}");
        return 0;
    }

    // A staging app deleted earlier still holds the slug (deleted apps keep theirs)
    $deleted = Platform_task::rows(
        $db,
        "SELECT id FROM app WHERE slug = ? AND environment = 'staging' AND deleted_at IS NOT NULL",
        [$app['slug']],
    );

    foreach ($deleted as $old) {
        $busy = Platform_task::row(
            $db,
            'SELECT id FROM tasks WHERE app_id = ? AND completed_at IS NULL AND failed_at IS NULL',
            [(int) $old['id']],
        );

        if ($busy !== null) {
            throw new RuntimeException("The previous staging app #{$old['id']} is still being removed; try again once it is gone");
        }

        Platform_task::execute($db, 'UPDATE app SET slug = ? WHERE id = ?', ['deleted-' . (int) $old['id'], (int) $old['id']]);
        $log->info("Freed the slug of deleted staging app #{$old['id']}");
    }

    $db->beginTransaction();

    try {
        Platform_task::execute(
            $db,
            "INSERT INTO app (project_id, label, slug, type, environment, production_app_id,
                source_url, source_provider, source_branch, auto_deploy,
                persistent_paths, php_extensions, build_commands, always_on, trongate_env, mail_capture, health_path)
             SELECT project_id, label, slug, type, 'staging', id,
                source_url, source_provider, ?, auto_deploy,
                persistent_paths, php_extensions, build_commands, 0, trongate_env, 1, health_path
             FROM app
             WHERE id = ?",
            [$staging_branch, $app_id],
        );
        $staging_id = (int) $db->lastInsertId();

        // App_env: still encrypted, so the values never pass through here
        Platform_task::execute(
            $db,
            "INSERT INTO secrets (module, module_id, service, variables)
             SELECT module, ?, service, variables
             FROM secrets
             WHERE module = 'app' AND module_id = ? AND service = 'env'",
            [$staging_id, $app_id],
        );

        Platform_task::execute($db, 'UPDATE app SET serves_slug_host = 0 WHERE id = ?', [$app_id]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $host = $app['slug'] . '.' . PLATFORM_APPS_DOMAIN;
    $log->info("Created staging app #{$staging_id}: {$host} now belongs to it; pushes to {$staging_branch} deploy it");

    // Production's route drops the {slug} host. A deploy queued or running does that itself.
    $busy = Platform_task::row(
        $db,
        "SELECT id FROM tasks WHERE app_id = ? AND script IN ('deploy', 'rollback') AND completed_at IS NULL AND failed_at IS NULL",
        [$app_id],
    );
    $current_id = (int) ($app['current_deployment_id'] ?? 0);
    $deployment = $current_id > 0 ? Platform_task::row($db, 'SELECT * FROM deployment WHERE id = ? AND app_id = ?', [$current_id, $app_id]) : null;

    if ($busy !== null || $deployment === null || empty($deployment['image'])) {
        return 0;
    }

    $app = Platform_task::app($db, $project_id, $app_id);
    $log->info("Re-applying production deployment #{$current_id} on " . implode(', ', Manifests::hostnames($app)));

    $database = Platform_task::database_server($db, $app, $log);
    Platform_task::apply($runtime, $log, $app, $deployment, $database);

    if (!Platform_task::wait_for_rollout($runtime, $log, $app)) {
        $log->error("Production did not roll out without {$host}; it still answers there until its next deploy");
        return 1;
    }

    return 0;
};
