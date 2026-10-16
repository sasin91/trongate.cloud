<?php
/**
 * The alert on the app's overview when its setup SQL stopped part way, with
 * the SQL that did not run and a Dismiss button (apps-setup_sql/dismiss).
 * Expects: $app, $setup_sql_failure (Setup_sql_records::setup_sql_failure),
 * $preview (true: no form).
 */
?>
<div class="ov-setup-sql" role="alert">
    <p><strong>The setup SQL did not finish.</strong> <?= htmlspecialchars($setup_sql_failure['message']) ?></p>
    <details>
        <summary>The SQL that did not run</summary>
        <pre><?= htmlspecialchars($setup_sql_failure['remaining_sql']) ?></pre>
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
