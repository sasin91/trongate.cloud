<?php
/**
 * projects/{id}/apps/new-git (Repository::create). Expects: $project, $app_types
 * (App_rules::APP_TYPES), $label and $type (the form's values).
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div class="ov">
    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <div class="card settings-card">
        <h2>New app on trongate.cloud git</h2>
        <p>Your app gets an empty git repository here. Push your Trongate app to its <code>main</code> branch and it deploys, no GitHub needed.</p>

        <?= form_open('projects/' . (int) $project->id . '/apps/new-git/submit') ?>
            <div class="form-group">
                <label for="label" class="form-label">App name</label>
                <input type="text" name="label" id="label" class="form-input" required minlength="3" maxlength="50" value="<?= $e($label) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="type" class="form-label">App type</label>
                <select name="type" id="type" class="form-input">
                    <?php foreach ($app_types as $value => $name): ?>
                    <option value="<?= $e($value) ?>"<?= $value === $type ? ' selected' : '' ?>><?= $e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Create app</button>
                <a href="projects/<?= (int) $project->id ?>" class="btn-secondary">Cancel</a>
            </div>
        <?= form_close() ?>
    </div>
</div>
