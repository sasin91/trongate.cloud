<?php
/**
 * Grid of app cards with their live state. Shared by the dashboard and the project page.
 *
 * Expects: $apps (rows from project_apps() with live_state), $new_app_href (empty state link, optional).
 */
?>
<div class="dash-grid">
    <?php foreach ($apps as $app): ?>
        <a href="apps/<?= (int) $app->id ?>" class="card">
            <div class="app-card-head">
                <h3><?= htmlspecialchars($app->label) ?></h3>
                <?php if ($app->live_state !== null): ?>
                    <span class="status-pill live-<?= htmlspecialchars($app->live_state['state']) ?>" title="<?= htmlspecialchars($app->live_state['detail']) ?>"><?= htmlspecialchars($app->live_state['label']) ?></span>
                <?php elseif (!empty($app->current_status)): ?>
                    <span class="status-pill status-<?= htmlspecialchars($app->current_status) ?>"><?= htmlspecialchars(str_replace('_', ' ', $app->current_status)) ?></span>
                <?php else: ?>
                    <span class="status-pill status-pending">not live</span>
                <?php endif; ?>
            </div>
            <div class="card-meta">
                <span><?= htmlspecialchars(!empty($app->domain_pointed_at) && !empty($app->domain_secured_at) && !empty($app->verified_domain) ? $app->verified_domain : $app->slug . '.' . PLATFORM_APPS_DOMAIN) ?></span>
                <?php if (!empty($app->current_commit_sha)): ?>
                    <span>&bull;</span>
                    <code><?= htmlspecialchars(substr($app->current_commit_sha, 0, 7)) ?></code>
                <?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>
    <?php if (empty($apps)): ?>
        <div class="empty-card">
            <p>No apps in this project yet.</p>
            <?php if (!empty($new_app_href)): ?>
                <a href="<?= htmlspecialchars($new_app_href) ?>" class="btn-create">Deploy an app</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
