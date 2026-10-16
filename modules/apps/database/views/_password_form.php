<?php
/**
 * The Change password form, loaded into a Trongate MX modal (mx-build-modal)
 * by Database::password_form. change_password answers with the table's URL,
 * which mx-redirect-on-success follows, or 422 with JSON errors that MX
 * shows next to the fields (highlight-errors).
 * The password fields are views/_password_fields.php, shared with the
 * new-app wizard's first administrator.
 * Expects: $base (apps/{id}/database), $table, $key, $column, $choices
 * (App_password::choices of the cell's value).
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<?= form_open($base . '/row/password/submit', [
    'class' => 'db-password-form highlight-errors',
    'autocomplete' => 'off',
    'mx-post' => $base . '/row/password/submit',
    'mx-target' => 'none',
    'mx-redirect-on-success' => 'true',
]) ?>
    <p class="db-dialog-what muted ov-mono"><?= $e($table) ?> row <?= $e(implode(', ', array_map(fn($name, $value) => $name . ' = ' . $value, array_keys($key), $key))) ?>, column <?= $e($column) ?>, now <?= $e($choices['current']) ?></p>
    <input type="hidden" name="table" value="<?= $e($table) ?>">
    <input type="hidden" name="column" value="<?= $e($column) ?>">
    <?php foreach ($key as $name => $value): ?>
        <input type="hidden" name="key[<?= $e($name) ?>]" value="<?= $e($value) ?>">
    <?php endforeach; ?>

    <?php require __DIR__ . '/_password_fields.php'; ?>

    <label class="checkbox-label db-backup-toggle">
        <input type="checkbox" name="backup" value="1" checked>
        Back up this row first, to restore the old hash under Backups
    </label>

    <p class="form-hint">The password is never stored or logged. Recent activity shows that this column changed, not its value.</p>

    <div class="form-actions">
        <button type="submit" class="btn-primary">Hash and save</button>
        <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
    </div>
<?= form_close() ?>
