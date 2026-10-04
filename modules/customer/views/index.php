<div class="content">
    <div class="section-header">
        <h2>My Projects</h2>
        <?php if (count($projects) > 0): ?>
            <a href="projects/new" class="btn-create">+ New Project</a>
        <?php endif; ?>
    </div>

    <div class="dash-grid">
        <?php if (count($projects) > 0): ?>
            <?php foreach ($projects as $project): ?>
                <a href="projects/<?= $project->id ?>" class="card">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                        <h3><?= htmlspecialchars($project->label) ?></h3>
                        <?php if ($project->live_state !== null): ?>
                            <span class="status-pill live-<?= htmlspecialchars($project->live_state['state']) ?>" title="<?= htmlspecialchars($project->live_state['detail']) ?>"><?= htmlspecialchars($project->live_state['label']) ?></span>
                        <?php elseif (!empty($project->current_status)): ?>
                            <span class="status-pill status-<?= htmlspecialchars($project->current_status) ?>"><?= htmlspecialchars(str_replace('_', ' ', $project->current_status)) ?></span>
                        <?php else: ?>
                            <span class="status-pill status-pending">not live</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-meta">
                        <span><?= htmlspecialchars(!empty($project->domain_pointed_at) && !empty($project->verified_domain) ? $project->verified_domain : $project->slug . '.' . PLATFORM_APPS_DOMAIN) ?></span>
                        <?php if (!empty($project->current_commit_sha)): ?>
                            <span>&bull;</span>
                            <code><?= htmlspecialchars(substr($project->current_commit_sha, 0, 7)) ?></code>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; background: white; padding: 3rem; text-align: center; border-radius: 0.75rem; border: 1px solid var(--border-color);">
                <h3 style="margin-top: 0;">Welcome to Trongate Cloud!</h3>
                <p style="color: var(--text-muted); margin-bottom: 2rem;">Pick a GitHub repository and we build, host and scale it for you.</p>
                <a href="projects/new" class="btn-create">Deploy a project</a>
            </div>
        <?php endif; ?>
    </div>
</div>
