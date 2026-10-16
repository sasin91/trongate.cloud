<?php
/**
 * The Database question's files: a checkbox per .sql file on the branch
 * (Setup_sql_plan::candidates), the likely ones ticked, and one for
 * Trongate's default tables when the branch has no setup.sql. sql_offered[]
 * and trongate_tables_offered post what was offered, so the form shows it
 * again after a failed submit.
 * Expects: $sql_files, $offer_trongate_tables, $trongate_tables_checked,
 * $read_failed (optional: GitHub could not be read).
 */
?>
<?php if ($offer_trongate_tables): ?>
    <div class="wz-sql-files">
        <label class="wz-check">
            <input type="hidden" name="trongate_tables_offered" value="1">
            <input type="checkbox" name="trongate_tables" value="1" <?= $trongate_tables_checked ? 'checked' : '' ?>>
            <span>Trongate's default tables: <code>trongate_users</code>, <code>trongate_user_levels</code>, <code>trongate_administrators</code>, <code>trongate_tokens</code>, plus the login module's <code>login_attempts</code> and <code>password_resets</code></span>
        </label>
    </div>
    <p class="wz-hint">Adds an <code>admin</code> administrator with the password <code>admin</code>. Change it as soon as the app is up.</p>
<?php endif; ?>
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
