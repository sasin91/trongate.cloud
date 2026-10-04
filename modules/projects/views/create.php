<div class="content narrow">
    <div class="back-link">
        <a href="dashboard" class="nav-link">&larr; Back to projects</a>
    </div>

    <h1>New project</h1>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <div class="card settings-card">
        <?= form_open('projects/new/submit') ?>
            <div class="form-group">
                <label for="name" class="form-label">Project name</label>
                <input type="text" name="name" id="name" class="form-input" placeholder="Acme Studio" value="<?= htmlspecialchars($name) ?>" required minlength="3" maxlength="50" autofocus>
                <p class="form-hint">A project holds apps that belong together. Its apps can reach each other; other projects cannot reach them. You can add people to it afterwards.</p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Create project</button>
                <a href="dashboard" class="btn-secondary">Cancel</a>
            </div>
        <?= form_close() ?>
    </div>
</div>
