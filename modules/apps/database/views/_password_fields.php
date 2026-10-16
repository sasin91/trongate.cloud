<?php
/**
 * A new password typed twice, Show password, and how to hash it
 * (App_password::choices), inside a form: the Database tab's Change password
 * (views/_password_form.php) and the new-app wizard's first administrator
 * (modules/apps/setup_sql/views/_administrator_form.php). Posts password,
 * password_confirm and algorithm.
 * Expects: $e (escapes), $choices.
 */
?>
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
        <p class="form-hint">Hashed on our side with PHP's <code>password_hash()</code>, which Trongate's login checks with <code>password_verify()</code>. If your app mixes a secret key (a pepper) into its hashes, set the password from your app instead.</p>
        <?php if ($choices['note'] !== null): ?>
            <p class="db-dialog-note"><?= $e($choices['note']) ?></p>
        <?php endif; ?>
    </div>
