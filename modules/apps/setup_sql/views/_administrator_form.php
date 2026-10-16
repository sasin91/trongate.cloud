<?php
/**
 * The new-app wizard's first administrator, loaded into a Trongate MX modal
 * (mx-build-modal) by Setup_sql::administrator_form. Setup_sql::administrator
 * hashes the password and answers with views/_administrator.php for the
 * wizard (mx-target), or 422 with JSON errors that MX shows next to the
 * fields (highlight-errors). The password fields are the Database tab's
 * Change password ones.
 * Expects: $choices (App_password::choices for an empty cell).
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<?= form_open('setup-sql/administrator/submit', [
    'class' => 'db-password-form highlight-errors',
    'autocomplete' => 'off',
    'mx-post' => 'setup-sql/administrator/submit',
    'mx-target' => '#wz-administrator',
    'mx-close-on-success' => 'true',
]) ?>
    <p class="form-hint">Added to <code>trongate_administrators</code> by the first deploy, so you can sign in to your app's admin panel.</p>
    <div class="form-group">
        <label for="wz-administrator-username" class="form-label">Username</label>
        <input type="text" name="username" id="wz-administrator-username" class="form-input" value="admin" maxlength="65" autocomplete="off" required>
    </div>
    <div class="form-group">
        <label for="wz-administrator-email" class="form-label">Email (optional)</label>
        <input type="email" name="email" id="wz-administrator-email" class="form-input" maxlength="255" autocomplete="off">
    </div>

    <?php require APPPATH . 'modules/apps/database/views/_password_fields.php'; ?>

    <p class="form-hint">Only the hash is kept, encrypted until the first deploy has run it. The password is never stored or logged.</p>

    <div class="form-actions">
        <button type="submit" class="btn-primary">Hash and add</button>
        <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
    </div>
<?= form_close() ?>
