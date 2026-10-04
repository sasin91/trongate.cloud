<div class="content narrow">
    <div class="back-link">
        <a href="apps/<?= $app->id ?>/settings" class="nav-link">&larr; Back to settings</a>
    </div>

    <h1>Delete <?= htmlspecialchars($app->label) ?></h1>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <div class="card settings-card danger-card">
        <p>Deleting the app removes it from your dashboard right away, and its app is taken offline within a few moments. This cannot be undone here.</p>

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
            <li>The address <code><?= htmlspecialchars($app->slug . '.' . PLATFORM_APPS_DOMAIN) ?></code>: it is not given to another app.</li>
        </ul>

        <?= form_open('apps/' . $app->id . '/delete/submit') ?>
            <div class="form-group">
                <label for="confirm_slug" class="form-label">Type <code><?= htmlspecialchars($app->slug) ?></code> to confirm</label>
                <input type="text" name="confirm_slug" id="confirm_slug" class="form-input mono" autocomplete="off" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-danger">Delete app</button>
                <a href="apps/<?= $app->id ?>/settings" class="btn-secondary">Cancel</a>
            </div>
        <?= form_close() ?>
    </div>
</div>
