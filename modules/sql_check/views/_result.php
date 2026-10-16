<?php
/**
 * What a live SQL check found (Sql_check::_show), shown under the SQL box
 * by Trongate MX (see Sql_check). Problems look like the
 * Rust compiler's errors.
 *
 * Expects: $refused, $summary, $problems (title, where, rows of gutter, pipe, text, kind),
 * $more_problems (null when none), $fix_lines and $fix_label (null hides the button).
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div class="sql-check-head">
    <p class="sql-check-summary<?= $refused ? ' sql-check-refused' : '' ?>"><?= $e($summary) ?></p>
    <?php if ($fix_label !== null): ?>
    <button type="button" class="sql-check-fix" data-sql-comment-lines="<?= $e($fix_lines) ?>"><?= $e($fix_label) ?></button>
    <?php endif; ?>
</div>
<?php foreach ($problems as $problem): ?>
<pre class="sql-check-problem"><span class="sql-check-error">error</span><strong>: <?= $e($problem['title']) ?></strong>
<span class="sql-check-gutter">  --&gt;</span> <?= $e($problem['where']) . "\n" ?><?php foreach ($problem['rows'] as $row): ?>
<span class="sql-check-gutter"><?= $e($row['gutter']) ?> <?= $e($row['pipe']) ?></span> <span class="sql-check-<?= $e($row['kind']) ?>"><?= $e($row['text']) ?></span>
<?php endforeach; ?></pre>
<?php endforeach; ?>
<?php if ($more_problems !== null): ?>
<p class="sql-check-more"><?= $e($more_problems) ?></p>
<?php endif; ?>
