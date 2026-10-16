<div class="content narrow">
    <div class="back-link">
        <a href="apps/<?= $app->id ?>/settings" class="nav-link">&larr; Back to settings</a>
    </div>

    <h1>Delete <?= htmlspecialchars($name) ?></h1>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <div class="card settings-card danger-card">
        <p>Deleting the app removes it from <?= htmlspecialchars((string) $app->project_name) ?> right away, for every member, and takes it offline within a few moments. This cannot be undone here.</p>

        <h3>Removed</h3>
        <ul class="delete-list">
            <?php foreach ($urls as $url): ?>
                <li>The app at <code><?= htmlspecialchars($url) ?></code></li>
            <?php endforeach; ?>
            <?php foreach ($staging_urls as $url): ?>
                <li>Its staging app at <code><?= htmlspecialchars($url) ?></code></li>
            <?php endforeach; ?>
            <li>Its access to its database, and any queued deployments</li>
        </ul>

        <?php if ($takes_backup): ?>
            <h3>Backed up, then deleted</h3>
            <ul class="delete-list">
                <li>The database and the files in your persistent paths. We save a copy of both first, then delete them.</li>
                <li>You can download the copy from the project page for <?= (int) $backup_keep_days ?> days. After that it is deleted for good.</li>
            </ul>
        <?php endif; ?>

        <h3>Kept</h3>
        <ul class="delete-list">
            <?php if (!$takes_backup): ?>
                <li>The database and the files in your persistent paths. They are not deleted with the app.</li>
            <?php endif; ?>
            <li>The address <code><?= htmlspecialchars($app->slug . '.' . PLATFORM_APPS_DOMAIN) ?></code>: it is not given to another app.</li>
        </ul>

        <?= form_open('apps/' . $app->id . '/delete/submit', ['id' => 'app-delete-form']) ?>
            <div class="form-group">
                <label for="confirm_slug" class="form-label">Type <code><?= htmlspecialchars($app->slug) ?></code> to confirm</label>
                <input type="text" name="confirm_slug" id="confirm_slug" class="form-input mono" autocomplete="off" required
                       data-slug="<?= htmlspecialchars($app->slug) ?>"
                       oninput="document.getElementById('app-delete-button').disabled = this.value.trim() !== this.dataset.slug"
                       onkeydown="if (event.key === 'Enter') event.preventDefault()">
            </div>

            <div class="form-actions">
                <button type="button" id="app-delete-button" class="btn-danger" disabled mx-get="<?= htmlspecialchars($confirm_url) ?>" mx-build-modal="<?= htmlspecialchars(Apps::DELETE_MODAL) ?>">Delete app</button>
                <a href="apps/<?= $app->id ?>/settings" class="btn-secondary">Cancel</a>
            </div>
        <?= form_close() ?>
    </div>
</div>
