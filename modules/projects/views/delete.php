<div class="content narrow">
    <div class="back-link">
        <a href="projects/<?= $project->id ?>/settings" class="nav-link">&larr; Back to settings</a>
    </div>

    <h1>Delete <?= htmlspecialchars($project->label) ?></h1>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <div class="card settings-card danger-card">
        <p>Deleting the project removes it from your dashboard right away, and its app is taken offline within a few moments. This cannot be undone here.</p>

        <h3>Removed</h3>
        <ul class="delete-list">
            <?php foreach ($urls as $url): ?>
                <li>The app at <code><?= htmlspecialchars($url) ?></code></li>
            <?php endforeach; ?>
            <li>Its access to the database, and any queued deployments</li>
        </ul>

        <h3>Kept</h3>
        <ul class="delete-list">
            <li>The database and the files in your persistent paths. They are not deleted with the app.</li>
            <li>The address <code><?= htmlspecialchars($project->slug . '.' . PLATFORM_APPS_DOMAIN) ?></code>: it is not given to another project.</li>
        </ul>

        <?= form_open('projects/' . $project->id . '/delete/submit') ?>
            <div class="form-group">
                <label for="confirm_slug" class="form-label">Type <code><?= htmlspecialchars($project->slug) ?></code> to confirm</label>
                <input type="text" name="confirm_slug" id="confirm_slug" class="form-input mono" autocomplete="off" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-danger">Delete project</button>
                <a href="projects/<?= $project->id ?>/settings" class="btn-secondary">Cancel</a>
            </div>
        <?= form_close() ?>
    </div>
</div>
