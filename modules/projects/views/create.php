<div class="onboarding-card">
    <div class="onboarding-header">
        <h1>New Project</h1>
        <p>Pick a repository. We build it and give it a live URL.</p>
    </div>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <?php if ($form === false): ?>
        <p>This team has not connected GitHub yet. Paste a
            <a href="https://github.com/settings/tokens" target="_blank" rel="noopener">personal access token</a>
            with the <code>repo</code> scope to continue.</p>

        <?= form_open('projects/submit_source') ?>
        <?= form_hidden('provider', 'github') ?>
        <div class="form-group">
            <label for="token" class="form-label">Personal Access Token</label>
            <input type="password" name="token" id="token" class="form-input" placeholder="Paste your GitHub token" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-primary">Connect GitHub</button>
            <a href="dashboard" class="btn-secondary">Cancel</a>
        </div>
        <?= form_close() ?>
    <?php else: ?>
        <?= form_open('projects/submit') ?>
        <?php
        extract($form);
        $branches_url = 'projects/branches';
        require __DIR__ . '/_project_fields.php';
        ?>
        <div class="form-actions">
            <button type="submit" class="btn-primary">Create &amp; Deploy</button>
            <a href="dashboard" class="btn-secondary">Cancel</a>
        </div>
        <?= form_close() ?>
    <?php endif; ?>
</div>
