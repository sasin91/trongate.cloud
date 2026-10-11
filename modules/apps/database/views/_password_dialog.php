<?php
/**
 * The Change password dialog, opened by js/password.js from a password cell
 * (views/index.php, right-click) or a row form button (views/row.php).
 * Expects: $base (apps/{id}/database), $table_name.
 *
 * Openers carry data-password-column, data-key (JSON primary key) and
 * data-hash (JSON App_password::choices of the cell's value).
 */
?>
<dialog id="db-password" class="db-dialog" aria-labelledby="db-password-h">
    <?= form_open($base . '/row/password', [
        'autocomplete' => 'off',
        'onsubmit' => "return this.password.value === this.password_confirm.value || (this.password_confirm.setCustomValidity('The passwords do not match.'), this.reportValidity(), false)",
    ]) ?>
        <h2 id="db-password-h">Change password</h2>
        <p class="db-dialog-what muted"><span class="ov-mono" data-password-where></span></p>
        <input type="hidden" name="table" value="<?= htmlspecialchars((string) $table_name) ?>">
        <input type="hidden" name="column" value="">
        <span data-password-key></span>

        <div class="form-group">
            <label for="db-password-new" class="form-label">New password</label>
            <input type="password" name="password" id="db-password-new" class="form-input" autocomplete="new-password" required>
        </div>
        <div class="form-group">
            <label for="db-password-confirm" class="form-label">Type it again</label>
            <input type="password" name="password_confirm" id="db-password-confirm" class="form-input" autocomplete="new-password" required oninput="this.setCustomValidity('')">
        </div>
        <label class="checkbox-label db-show-password">
            <input type="checkbox" onchange="this.form.password.type = this.form.password_confirm.type = this.checked ? 'text' : 'password'">
            Show password
        </label>

        <div class="form-group">
            <label for="db-password-algorithm" class="form-label">Hash with</label>
            <select name="algorithm" id="db-password-algorithm" class="form-input"></select>
            <p class="form-hint">Now: <span data-password-current></span>. Hashed on our side with PHP's <code>password_hash()</code>, which Trongate's login checks with <code>password_verify()</code>. If your app mixes a secret key (a pepper) into its hashes, set the password from your app instead.</p>
            <p class="db-dialog-note" data-password-note hidden></p>
        </div>

        <label class="checkbox-label db-backup-toggle">
            <input type="checkbox" name="backup" value="1" checked>
            Back up this row first, to restore the old hash under Backups
        </label>

        <p class="form-hint">The password is never stored or logged. Recent activity shows that this column changed, not its value.</p>

        <div class="form-actions">
            <button type="submit" class="btn-primary">Hash and save</button>
            <button type="button" class="btn-secondary" data-password-cancel>Cancel</button>
        </div>
    <?= form_close() ?>
</dialog>
