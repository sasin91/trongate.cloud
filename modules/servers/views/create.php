<div class="onboarding-card">
    <div class="onboarding-header">
        <h1>Provision New Server</h1>
        <p>Create a fresh VPS for your projects.</p>
    </div>
    
    <form action="servers/submit" method="post">
        <div class="form-group">
            <label class="form-label">Server Name (Label)</label>
            <input type="text" name="label" required placeholder="production-01" value="<?= htmlspecialchars($label) ?>" class="form-input">
        </div>
        
        <div class="form-group">
            <label class="form-label">Location</label>
            <select name="region" class="form-select"
                    mx-get="servers/server_types_options?location=${this.value}"
                    mx-trigger="change"
                    mx-target="#server-type-select"
                    mx-swap="innerHTML">
                <?php foreach($regions as $region): ?>
                    <option value="<?= $region['id'] ?>"><?= $region['name'] ?> (<?= $region['country'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Server Type</label>
            <select name="server_type" id="server-type-select" class="form-select">
                <?php require __DIR__ . '/_server_type_options.php'; ?>
            </select>
        </div>

        <input type="hidden" name="provider" value="hetzner">
        <div class="form-group">
            <label class="form-label">OS Image</label>
            <div class="form-input" style="background: var(--bg-app); cursor: not-allowed;">
                Docker CE (Ubuntu)
            </div>
            <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.5rem;">Hetzner's docker-ce image with Docker and docker-compose pre-installed.</p>
        </div>

        <div class="form-group">
            <label class="form-label">Project</label>
            <?php if ($project === null): ?>
                <select name="project_id" class="form-select">
                    <option value="">-- No Project --</option>
                    <?php if (!empty($projects)): ?>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?= $proj->id ?>"><?= $proj->label ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            <?php else: ?>
                <input type="hidden" name="project_id" value="<?= $project->id ?>">
                <div class="form-input" style="background: var(--bg-app); cursor: not-allowed;">
                    <?= $project->label ?>
                </div>
            <?php endif; ?>
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
             <button type="submit" class="btn-primary" style="flex: 1;" onclick="return confirm('This will create a new billable server on your Hetzner Cloud account. Continue?');">Provision Server</button>
             <a href="customer" class="btn-secondary" style="flex: 1; text-align: center;">Cancel</a>
        </div>
    </form>
</div>
