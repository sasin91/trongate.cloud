<?php
/**
 * The overview's alert about a setup SQL that did not finish.
 *
 * Expects: $app, $setup_sql_failure (Setup::_failure, not null), $preview
 * (true: no dismiss form) and $e (htmlspecialchars), as _overview.php has them.
 */
?>
<div class="ov-setup-sql" role="alert">
    <p><strong>The setup SQL did not finish.</strong> <?= $e($setup_sql_failure['message']) ?></p>
    <details>
        <summary>The SQL that did not run</summary>
        <pre><?= $e($setup_sql_failure['remaining_sql']) ?></pre>
    </details>
    <div class="ov-setup-sql-foot">
        <p class="muted">Fix it and run it from the <a href="apps/<?= (int) $app->id ?>/database?view=sql">Database tab</a>. Dismissing deletes this SQL.</p>
        <?php if (!$preview): ?>
            <?= form_open('apps/' . $app->id . '/setup-sql/dismiss') ?>
                <button type="submit" class="btn-link">Dismiss</button>
            <?= form_close() ?>
        <?php endif; ?>
    </div>
</div>
