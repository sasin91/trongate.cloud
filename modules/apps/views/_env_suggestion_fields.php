<?php
/**
 * The Environment question's inputs: one per variable the app's config/*.php reads.
 * Expects: $env_fields (App_form::_env_fields), $read_failed (optional: GitHub could not be read).
 */
?>
<?php if (!empty($read_failed)): ?>
    <p class="wz-hint">We could not read <code>config/</code> from GitHub. You can add variables later on the app's Settings page.</p>
<?php elseif ($env_fields === []): ?>
    <p class="wz-hint">Nothing in <code>config/</code> reads an environment variable. You can add variables later on the app's Settings page.</p>
<?php else: ?>
    <div class="wz-env">
        <?php foreach ($env_fields as $field): ?>
            <label class="wz-field">
                <span class="wz-label">
                    <code><?= htmlspecialchars($field['name']) ?></code>
                    <?php if ($field['file'] !== ''): ?><small><?= htmlspecialchars($field['file']) ?></small><?php endif; ?>
                </span>
                <input type="<?= htmlspecialchars($field['input_type']) ?>" name="env[<?= htmlspecialchars($field['name']) ?>]" class="wz-in mono" value="<?= htmlspecialchars($field['value']) ?>" autocomplete="new-password" spellcheck="false">
            </label>
        <?php endforeach; ?>
    </div>
    <p class="wz-hint">Leave one blank to skip it. Values are stored encrypted and never shown again.</p>
<?php endif; ?>
