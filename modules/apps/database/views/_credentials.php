<?php
/**
 * The Credentials dialog, loaded into a Trongate MX modal (mx-build-modal)
 * by Database::credentials. Show posts to show_password, which logs it and
 * answers with views/_credentials_password.php in place of the dots.
 * Copy buttons are handled by js/copy.js.
 * Expects: $base (apps/{id}/database), $error (null, or why there is nothing
 * to show), $fields (label, value).
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div class="db-credentials">
    <?php if ($error !== null): ?>
        <p class="db-dialog-what"><?= $e($error) ?></p>
    <?php else: ?>
    <dl class="db-credentials-list">
        <?php foreach ($fields as $field): ?>
        <dt><?= $e($field['label']) ?></dt>
        <dd>
            <code><?= $e($field['value']) ?></code>
            <button type="button" class="db-credentials-button db-copy" data-copy="<?= $e($field['value']) ?>">Copy</button>
        </dd>
        <?php endforeach; ?>
        <dt>Password</dt>
        <dd id="db-credentials-password">
            <code>••••••••••••</code>
            <?= form_open($base . '/credentials/password', [
                'class' => 'db-credentials-show',
                'mx-post' => $base . '/credentials/password',
                'mx-target' => '#db-credentials-password',
            ]) ?>
                <button type="submit" class="db-credentials-button">Show</button>
            <?= form_close() ?>
        </dd>
    </dl>

    <p class="form-hint">Your app already connects with these: trongate.cloud writes them into its <code>config/database.php</code> on every deploy.</p>
    <p class="form-hint">The host only answers inside trongate.cloud, so you cannot connect to it from your own computer. Use this tab, or Import, to work with the data.</p>
    <p class="form-hint">Showing the password is recorded in History.</p>
    <?php endif; ?>

    <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="closeModal()">Close</button>
    </div>
</div>
