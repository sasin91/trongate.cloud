<?php
/**
 * The Database question's files: a checkbox per .sql file on the branch
 * (Setup_sql_plan::candidates), the likely ones ticked. sql_offered[] posts the
 * whole list, so the form shows it again after a failed submit.
 * Expects: $sql_files, $read_failed (optional: GitHub could not be read).
 */
?>
<?php if (!empty($read_failed)): ?>
    <p class="wz-hint">We could not list the repository's files. Type a path below instead.</p>
<?php elseif ($sql_files === []): ?>
    <p class="wz-hint">No <code>.sql</code> files in the usual places (<code>db/</code>, <code>database/</code>, <code>sql/</code>, the root or a module's folder).</p>
<?php else: ?>
    <div class="wz-sql-files">
        <?php foreach ($sql_files as $file): ?>
            <label class="wz-check">
                <input type="hidden" name="sql_offered[]" value="<?= htmlspecialchars($file['path']) ?>">
                <input type="checkbox" name="sql_files[]" value="<?= htmlspecialchars($file['path']) ?>" <?= $file['checked'] ? 'checked' : '' ?>>
                <code><?= htmlspecialchars($file['path']) ?></code>
            </label>
        <?php endforeach; ?>
    </div>
    <p class="wz-hint">Ticked files run in this order.</p>
<?php endif; ?>
