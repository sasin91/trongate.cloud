<div class="content">
    <div class="section-header">
        <h2>My Projects</h2>
        <?php if (count($projects) > 0): ?>
            <a href="projects/create" class="btn-create">+ New Project</a>
        <?php endif; ?>
    </div>

    <div class="dash-grid">
        <?php if (count($projects) > 0): ?>
            <?php foreach ($projects as $project): ?>
                <a href="projects/show/<?= $project->id ?>" class="card">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                        <h3><?= $project->label ?></h3>
                        <?php if(isset($project->server_status)): ?>
                            <span class="status-pill status-<?= $project->server_status ?>"><?= $project->server_status ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="card-meta">
                        <span><?= ucfirst($project->type) ?></span>
                        <?php if(isset($project->server_address)): ?>
                            <span>•</span>
                            <span><?= $project->server_address ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; background: white; padding: 3rem; text-align: center; border-radius: 0.75rem; border: 1px solid var(--border-color);">
                <h3 style="margin-top: 0;">Welcome to Trongate Cloud!</h3>
                <p style="color: var(--text-muted); margin-bottom: 2rem;">Get started by setting up your infrastructure.</p>
                
                <div style="display: inline-flex; gap: 2rem; text-align: left;">
                    <div>
                        <h4 style="margin: 0 0 0.5rem 0;">1. Provision Server</h4>
                        <p style="margin: 0 0 1rem 0; font-size: 0.875rem; color: var(--text-muted);">Create a VPS to host your apps.</p>
                        <a href="servers/create" class="btn-create">Create Server</a>
                    </div>
                    <div style="border-left: 1px solid var(--border-color); padding-left: 2rem;">
                        <h4 style="margin: 0 0 0.5rem 0;">2. Deploy Project</h4>
                        <p style="margin: 0 0 1rem 0; font-size: 0.875rem; color: var(--text-muted);">Launch your application.</p>
                        <a href="projects/create" class="btn-create" style="background: white; color: var(--accent); border: 1px solid var(--accent);">Deploy Project</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>