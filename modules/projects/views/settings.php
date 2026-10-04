<div class="content narrow">
    <div class="back-link">
        <a href="projects/<?= (int) $project->id ?>" class="nav-link">&larr; Back to <?= htmlspecialchars($project->name) ?></a>
    </div>

    <h1>Project settings</h1>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <div class="card settings-card">
        <?= form_open('projects/' . (int) $project->id . '/settings/submit') ?>
            <div class="form-group">
                <label for="name" class="form-label">Project name</label>
                <input type="text" name="name" id="name" class="form-input" value="<?= htmlspecialchars($name) ?>" required minlength="3" maxlength="50">
                <p class="form-hint">Only the name changes. Your apps keep their addresses.</p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Save</button>
                <a href="projects/<?= (int) $project->id ?>" class="btn-secondary">Cancel</a>
            </div>
        <?= form_close() ?>
    </div>

    <h2>Delete project</h2>
    <div class="card settings-card danger-card">
        <?php if ($app_count > 0): ?>
            <p>This project still holds <?= $app_count ?> <?= $app_count === 1 ? 'app' : 'apps' ?>. Delete <?= $app_count === 1 ? 'it' : 'them' ?> from <?= $app_count === 1 ? 'its' : 'their' ?> settings first, then come back here.</p>
        <?php else: ?>
            <p>The project holds no apps. Deleting it removes it for every member. This cannot be undone.</p>
            <?= form_open('projects/' . (int) $project->id . '/delete', ['onsubmit' => "return confirm('Delete this project for every member?')"]) ?>
                <div class="form-actions">
                    <button type="submit" class="btn-danger">Delete project</button>
                </div>
            <?= form_close() ?>
        <?php endif; ?>
    </div>
</div>
