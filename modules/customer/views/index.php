<div class="content">
    <?= flashdata('<p class="flash">', '</p>') ?>

    <div class="section-header">
        <h2>My Apps</h2>
        <?php if (count($apps) > 0): ?>
            <a href="apps/new" class="btn-create">+ New App</a>
        <?php endif; ?>
    </div>

    <div class="dash-grid">
        <?php if (count($apps) > 0): ?>
            <?php foreach ($apps as $app): ?>
                <a href="apps/<?= $app->id ?>" class="card">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
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
        <?php else: ?>
            <div style="grid-column: 1 / -1; background: white; padding: 3rem; text-align: center; border-radius: 0.75rem; border: 1px solid var(--border-color);">
                <h3 style="margin-top: 0;">Welcome to Trongate Cloud!</h3>
                <p style="color: var(--text-muted); margin-bottom: 2rem;">Pick a GitHub repository and we build, host and scale it for you.</p>
                <a href="apps/new" class="btn-create">Deploy a app</a>
            </div>
        <?php endif; ?>
    </div>
</div>
