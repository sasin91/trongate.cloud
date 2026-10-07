<?php
/**
 * App page header: name, live state, address, Deploy/Roll back and the section tabs.
 * Shared by the overview (_overview.php), the logs page (logs.php) and the database page (database/views/index.php).
 *
 * Expects: $app, $urls, $live_state (array|null), $can_rollback, $active_tab
 * ('overview', 'logs' or 'database'), and optionally $preview (true: no forms, no polling).
 */
$preview = $preview ?? false;
$e ??= fn($value) => htmlspecialchars((string) $value);
$repository ??= preg_replace('#^https?://(www\.)?github\.com/#', '', (string) $app->source_url);
$auto_deploy ??= !isset($app->auto_deploy) || !empty($app->auto_deploy);
?>
    <div class="ov-head">
        <div class="ov-title">
            <div class="ov-title-row">
                <h1><?= $e($app->label) ?></h1>
                <span class="ov-live" id="live-state"<?php if (!$preview && $live_state !== null): ?> mx-get="apps/<?= (int) $app->id ?>/live-state" mx-trigger="every 5s"<?php endif; ?>>
                    <?php if ($live_state !== null): ?>
                        <?php require __DIR__ . '/_live_state.php'; ?>
                    <?php else: ?>
                        <span class="status-pill status-pending">not live yet</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="ov-meta">
                <a href="<?= $e($urls[count($urls) - 1]) ?>" target="_blank" rel="noopener" class="ov-mono"><?= $e(preg_replace('#^https://#', '', $urls[count($urls) - 1])) ?></a>
                <span><?= $e($repository) ?> &middot; <?= $e($app->source_branch) ?></span>
                <span><?= $auto_deploy ? 'Deploys on push' : 'Manual deploys' ?></span>
            </div>
        </div>
        <div class="ov-actions">
            <?php if ($preview): ?>
                <span class="btn-secondary">Roll back</span>
                <span class="btn-primary">Deploy now</span>
            <?php else: ?>
                <?php if ($can_rollback): ?>
                    <?= form_open('apps/' . $app->id . '/rollback', ['onsubmit' => "return confirm('Roll back to the previous successful deployment?')"]) ?>
                        <button type="submit" class="btn-secondary">Roll back</button>
                    <?= form_close() ?>
                <?php endif; ?>
                <?= form_open('apps/' . $app->id . '/deploy') ?>
                    <button type="submit" class="btn-primary">Deploy now</button>
                <?= form_close() ?>
            <?php endif; ?>
        </div>
    </div>

    <nav class="ov-tabs" aria-label="App sections">
        <a href="apps/<?= (int) $app->id ?>"<?= $active_tab === 'overview' ? ' aria-current="page"' : '' ?>>Overview</a>
        <a href="apps/<?= (int) $app->id ?>#deployments">Deployments</a>
        <a href="apps/<?= (int) $app->id ?>/logs"<?= $active_tab === 'logs' ? ' aria-current="page"' : '' ?>>Logs</a>
        <a href="apps/<?= (int) $app->id ?>/database"<?= $active_tab === 'database' ? ' aria-current="page"' : '' ?>>Database</a>
        <a href="apps/<?= (int) $app->id ?>/settings">Settings</a>
    </nav>
