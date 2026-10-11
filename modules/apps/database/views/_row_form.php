<?php
/**
 * Edit one row, or add one when $key is empty, loaded into a Trongate MX modal
 * (mx-build-modal) by Database::row. submit_row answers with the table's URL,
 * which mx-redirect-on-success follows, or 422 with JSON errors that MX shows
 * next to the fields (highlight-errors); a refusal from MariaDB shows at the top.
 * Deleting is a second, ordinary form under it.
 *
 * Expects: $base (apps/{id}/database), $table, $key, $back_query (the table page
 * to go back to), $fields (Database::_row_fields).
 */
$e = fn($value) => htmlspecialchars((string) $value);
$is_new = $key === [];
?>
<?= form_open($base . '/row/submit?' . $back_query, [
    'class' => 'db-row-form highlight-errors',
    'mx-post' => $base . '/row/submit?' . $back_query,
    'mx-target' => 'none',
    'mx-redirect-on-success' => 'true',
]) ?>
    <p class="db-dialog-what muted ov-mono"><?= $e($table) ?><?php foreach ($key as $name => $value): ?> &middot; <?= $e($name) ?> = <?= $e($value) ?><?php endforeach; ?></p>
    <input type="hidden" name="row" value="1">
    <input type="hidden" name="table" value="<?= $e($table) ?>">
    <?php foreach ($key as $name => $value): ?>
        <input type="hidden" name="key[<?= $e($name) ?>]" value="<?= $e($value) ?>">
    <?php endforeach; ?>

    <div class="db-fields">
        <?php foreach ($fields as $field): ?>
        <div class="db-field<?= $field['wide'] ? ' db-field-wide' : '' ?>">
            <label for="<?= $e($field['id']) ?>" class="db-field-label">
                <span><?= $e($field['name']) ?></span>
                <span class="db-type"><?= $e($field['type']) ?></span>
            </label>
            <?php if ($field['input'] === 'none'): ?>
                <p class="db-field-fixed" id="<?= $e($field['id']) ?>"><?= $e($field['read_only_text']) ?></p>
            <?php elseif ($field['input'] === 'textarea'): ?>
                <textarea name="fields[<?= $e($field['name']) ?>]" id="<?= $e($field['id']) ?>" class="form-input mono" rows="4">
<?= $e($field['value']) ?></textarea>
            <?php else: ?>
                <input type="text" name="fields[<?= $e($field['name']) ?>]" id="<?= $e($field['id']) ?>" class="form-input mono" value="<?= $e($field['value']) ?>"<?= $field['placeholder'] !== '' ? ' placeholder="' . $e($field['placeholder']) . '"' : '' ?>>
            <?php endif; ?>
            <span class="db-field-links">
                <?php if ($field['nullable']): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="null[<?= $e($field['name']) ?>]" value="1"<?= $field['is_null'] ? ' checked' : '' ?>>
                        NULL when empty
                    </label>
                <?php endif; ?>
                <?php if ($field['password_url'] !== null): ?>
                    <button type="button" class="db-link-button" mx-get="<?= $e($field['password_url']) ?>" mx-build-modal="<?= $e(Database::PASSWORD_MODAL) ?>">Change password…</button>
                <?php endif; ?>
                <?php if ($field['reference_href'] !== null): ?>
                    <a href="<?= $e($field['reference_href']) ?>">Open in <?= $e($field['references']) ?> &rarr;</a>
                <?php elseif ($field['references'] !== ''): ?>
                    <span class="muted">&rarr; <?= $e($field['references']) ?></span>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($is_new): ?>
        <p class="form-hint">Leave a field empty to use its default.</p>
    <?php else: ?>
        <label class="checkbox-label db-backup-toggle">
            <input type="checkbox" name="backup" value="1" checked>
            Back up this row first, to restore it under Backups
        </label>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn-primary"><?= $is_new ? 'Add row' : 'Save row' ?></button>
        <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
    </div>
<?= form_close() ?>

<?php if (!$is_new): ?>
<?= form_open($base . '/row/delete?' . $back_query, [
    'class' => 'db-row-delete',
    'onsubmit' => "return confirm(this.backup.checked ? 'Delete this row? It is backed up first, so you can restore it under Backups.' : 'Delete this row without a backup? This cannot be undone.')",
]) ?>
    <input type="hidden" name="table" value="<?= $e($table) ?>">
    <?php foreach ($key as $name => $value): ?>
        <input type="hidden" name="key[<?= $e($name) ?>]" value="<?= $e($value) ?>">
    <?php endforeach; ?>
    <label class="checkbox-label">
        <input type="checkbox" name="backup" value="1" checked>
        Back up first
    </label>
    <button type="submit" class="btn-danger">Delete row</button>
<?= form_close() ?>
<?php endif; ?>
