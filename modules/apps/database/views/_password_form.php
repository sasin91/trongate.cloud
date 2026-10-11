<?php
/**
 * The Change password form, loaded into a Trongate MX modal (mx-build-modal)
 * by Database::password_form. change_password answers with the table's URL,
 * which mx-redirect-on-success follows, or 422 with JSON errors that MX
 * shows next to the fields (highlight-errors).
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
    <p class="db-dialog-what muted ov-mono"><?= $e($table) ?> row <?= $e(implode(', ', array_map(fn($name, $value) => $name . ' = ' . $value, array_keys($key), $key))) ?>, column <?= $e($column) ?></p>
    <input type="hidden" name="table" value="<?= $e($table) ?>">
    <input type="hidden" name="column" value="<?= $e($column) ?>">
    <?php foreach ($key as $name => $value): ?>
        <input type="hidden" name="key[<?= $e($name) ?>]" value="<?= $e($value) ?>">
    <?php endforeach; ?>

    <div class="form-group">
        <label for="db-password-new" class="form-label">New password</label>
        <input type="password" name="password" id="db-password-new" class="form-input" autocomplete="new-password" required autofocus>
    </div>
    <div class="form-group">
        <label for="db-password-confirm" class="form-label">Type it again</label>
        <input type="password" name="password_confirm" id="db-password-confirm" class="form-input" autocomplete="new-password" required>
    </div>
    <label class="checkbox-label db-show-password">
        <input type="checkbox" onchange="this.form.password.type = this.form.password_confirm.type = this.checked ? 'text' : 'password'">
        Show password
    </label>

    <div class="form-group">
        <label for="db-password-algorithm" class="form-label">Hash with</label>
        <select name="algorithm" id="db-password-algorithm" class="form-input">
            <?php foreach ($choices['choices'] as $value => $label): ?>
                <option value="<?= $e($value) ?>"<?= $value === $choices['default'] ? ' selected' : '' ?>><?= $e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="form-hint">Now: <?= $e($choices['current']) ?>. Hashed on our side with PHP's <code>password_hash()</code>, which Trongate's login checks with <code>password_verify()</code>. If your app mixes a secret key (a pepper) into its hashes, set the password from your app instead.</p>
        <?php if ($choices['note'] !== null): ?>
            <p class="db-dialog-note"><?= $e($choices['note']) ?></p>
        <?php endif; ?>
    </div>

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
