<?php
/**
 * The Database question's files: Trongate's default tables when the branch
 * has no setup.sql (a ticked checkbox per set, its SQL editable and checked
 * live by sql/check), then a checkbox per .sql file on the branch
 * (Setup_sql_plan::candidates), the likely ones ticked.
 * trongate_tables_offered and sql_offered[] post what was offered, so the
 * form shows it again after a failed submit.
 * Expects: $trongate_tables (Setup_sql_step::_trongate_tables_option each),
 * $sql_files, $read_failed (optional: GitHub could not be read).
 */
?>
<?php if ($trongate_tables !== []): ?>
    <input type="hidden" name="trongate_tables_offered" value="1">
    <div class="wz-sql-sets">
        <?php foreach ($trongate_tables as $option): ?>
            <div class="wz-sql-set">
                <label class="wz-check">
                    <input type="checkbox" name="trongate_tables[]" value="<?= htmlspecialchars($option['set']) ?>" <?= $option['checked'] ? 'checked' : '' ?>>
                    <span>Trongate <?= htmlspecialchars(lcfirst($option['label'])) ?>:
                        <?php foreach ($option['tables'] as $table): ?>
                            <code><?= htmlspecialchars($table) ?></code>
                        <?php endforeach; ?>
                    </span>
                </label>
                <details class="wz-sql-edit">
                    <summary>Edit SQL</summary>
                    <textarea name="<?= htmlspecialchars($option['sql_field']) ?>" class="wz-in mono wz-sql" rows="10" spellcheck="false" aria-describedby="wz-sql-check-<?= htmlspecialchars($option['set']) ?>" mx-post="sql/check" mx-trigger="change" mx-target="#wz-sql-check-<?= htmlspecialchars($option['set']) ?>" mx-vals='{"rules":"setup","sql_field":"<?= htmlspecialchars($option['sql_field']) ?>"}'><?= htmlspecialchars($option['sql']) ?></textarea>
                    <div id="wz-sql-check-<?= htmlspecialchars($option['set']) ?>" class="sql-check wz-sql-check" aria-live="polite"></div>
                </details>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="wz-hint">Trongate's tables run first, before the files below.</p>
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
