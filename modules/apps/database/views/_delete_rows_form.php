<?php
/**
 * Asks before Delete selected, loaded into a Trongate MX modal (mx-build-modal)
 * by Database::delete_rows_form. The ticked rows are in the grid's form
 * (#db-rows-form); the backup box and the Delete button belong to it through
 * their form attribute, so it posts to Database::delete_rows.
 * Expects: $table, $rows (how many, as "3 rows").
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div class="db-delete-rows">
    <p>Delete <?= $e($rows) ?> from <span class="ov-mono"><?= $e($table) ?></span>? They are deleted together: if one cannot be, none are.</p>

    <label class="checkbox-label db-backup-toggle">
        <input type="checkbox" name="backup" value="1" form="db-rows-form" checked>
        Back up these rows first, to restore them under Backups
    </label>

    <div class="form-actions">
        <button type="submit" class="btn-danger" form="db-rows-form">Delete <?= $e($rows) ?></button>
        <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
    </div>
</div>
