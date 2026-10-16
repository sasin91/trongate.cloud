<?php
/**
 * What a live SQL check found (Sql_check_response::_show_sql_check), shown
 * under the SQL box by apps_module/js/sql_check.js.
 *
 * Expects: $refused, $summary, $problems (where, preview, message), $more_problems (null when none).
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<p class="sql-check-summary<?= $refused ? ' sql-check-refused' : '' ?>"><?= $e($summary) ?></p>
<?php if ($problems !== []): ?>
<ul class="sql-check-problems">
    <?php foreach ($problems as $problem): ?>
    <li>
        <span class="sql-check-where"><?= $e($problem['where']) ?></span>
        <code title="<?= $e($problem['preview']) ?>"><?= $e($problem['preview']) ?></code>
        <span class="sql-check-message"><?= $e($problem['message']) ?></span>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($more_problems !== null): ?>
<p class="sql-check-more"><?= $e($more_problems) ?></p>
<?php endif; ?>
