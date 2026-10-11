<?php
/**
 * The SQL view of views/index.php: the editor and its toolbar, then Result,
 * History and Backups as tabs under it.
 *
 * Expects (Database::_workspace_data): $view (sql, history or backups), $query_url,
 * $database_name, $sql, $sql_placeholder, $statement_seconds, $query_error,
 * $result_panels, $results_summary, $backup_link, $history, $backup_entries,
 * $sql_url, $history_url, $backups_url, $base.
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<?= form_open($query_url, [
    'class' => 'db-sql',
    'onsubmit' => "return !this.allow_changes.checked || confirm(this.backup.checked"
        . " ? 'Run this SQL with changes allowed? The tables it names are backed up first, so you can restore them under Backups.'"
        . " : 'Run this SQL with changes allowed and no backup? It can change or delete data in " . $e($database_name) . " for good.')",
]) ?>
    <textarea name="sql" id="db-sql" aria-label="SQL statements" class="db-editor" rows="5" spellcheck="false" placeholder="<?= $e($sql_placeholder) ?>"><?= $e($sql) ?></textarea>
    <div class="db-sql-bar">
        <button type="submit" class="btn-primary db-run">&#9654; Run</button>
        <label class="checkbox-label">
            <input type="checkbox" name="allow_changes" value="1" id="db-allow-changes" onchange="this.form.backup.disabled = !this.checked">
            Allow changes
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="backup" value="1" id="db-backup" checked disabled>
            Back up first
        </label>
        <span class="db-sql-hint" title="Separate statements with ;. They run in one transaction as your app's own database user, so if one fails the others are rolled back. CREATE, ALTER, DROP, TRUNCATE and RENAME commit at once, with everything before them. Read-only unless you allow changes. Deploys never change your tables: run your modules' .sql files here.">
            One transaction &middot; read-only unless you allow changes &middot; stops after <?= (int) $statement_seconds ?> s &middot; logged
        </span>
    </div>
<?= form_close() ?>

<nav class="db-subtabs" aria-label="SQL panels">
    <a href="<?= $e($sql_url) ?>"<?= $view === 'sql' ? ' aria-current="page"' : '' ?>>Result</a>
    <a href="<?= $e($history_url) ?>"<?= $view === 'history' ? ' aria-current="page"' : '' ?>>History</a>
    <a href="<?= $e($backups_url) ?>"<?= $view === 'backups' ? ' aria-current="page"' : '' ?>>Backups &middot; <?= count($backup_entries) ?></a>
</nav>

<div class="db-panel">
<?php if ($view === 'history'): ?>
    <?php if ($history === []): ?>
        <p class="db-empty-rows">Nothing run yet. Statements and row changes show up here, times in UTC.</p>
    <?php else: ?>
    <ul class="db-log">
        <?php foreach ($history as $entry): ?>
        <li>
            <span class="muted"><?= $e($entry['when']) ?> &middot; <?= $e($entry['who']) ?></span>
            <code title="<?= $e($entry['statement']) ?>"><?= $e($entry['statement_short']) ?></code>
            <span class="<?= $entry['failed'] ? 'db-log-error' : 'muted' ?>"><?= $e($entry['outcome']) ?><?php if ($entry['backup_href'] !== null): ?> &middot; <a href="<?= $e($entry['backup_href']) ?>">backup</a><?php endif; ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
<?php elseif ($view === 'backups'): ?>
    <?php if ($backup_entries === []): ?>
        <p class="db-empty-rows">No backups yet. Changes made with Back up first ticked are kept here for <?= Database_model::BACKUP_DAYS ?> days.</p>
    <?php else: ?>
    <ul class="db-log db-backups">
        <?php foreach ($backup_entries as $backup): ?>
        <li id="backup-<?= (int) $backup['id'] ?>"<?= $backup['is_new'] ? ' class="db-backup-new"' : '' ?>>
            <span class="muted"><?= $e($backup['taken']) ?> &middot; <?= $e($backup['who']) ?> &middot; <?= $e($backup['size']) ?></span>
            <span class="db-backup-what">
                <strong class="ov-mono"><?= $e($backup['summary']) ?></strong>
                <?php if ($backup['statement'] !== null): ?>
                    <span class="muted">before</span> <code title="<?= $e($backup['statement']) ?>"><?= $e($backup['statement_short']) ?></code>
                <?php endif; ?>
                <?php if ($backup['failed']): ?><span class="db-log-error">(that failed, so nothing changed)</span><?php endif; ?>
            </span>
            <?= form_open($base . '/backups/restore', [
                'class' => 'db-restore',
                'onsubmit' => 'return confirm(' . json_encode($backup['confirm'], JSON_INVALID_UTF8_SUBSTITUTE) . ')',
            ]) ?>
                <input type="hidden" name="backup" value="<?= (int) $backup['id'] ?>">
                <?php if ($backup['restored'] !== null): ?><span class="muted">restored <?= $e($backup['restored']) ?></span><?php endif; ?>
                <button type="submit" class="db-button">Restore</button>
            <?= form_close() ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
<?php elseif ($query_error !== null): ?>
    <p class="db-error" role="alert"><?= $e($query_error) ?></p>
<?php elseif ($result_panels === []): ?>
    <p class="db-empty-rows">Run a statement to see its result here.</p>
<?php else: ?>
    <?php if ($results_summary !== null): ?>
        <p class="db-done" role="status"><?= $e($results_summary) ?>.</p>
    <?php endif; ?>
    <?php foreach ($result_panels as $i => $panel): ?>
        <p class="db-done"<?= count($result_panels) === 1 ? ' role="status"' : '' ?>>
            <?php if (count($result_panels) > 1): ?>
                <span class="db-statement"><?= $i + 1 ?>. <code title="<?= $e($panel['statement']) ?>"><?= $e($panel['statement_short']) ?></code></span>
            <?php endif; ?>
            <?= $e($panel['outcome']) ?>.
        </p>
        <?php if ($panel['shows_rows']): ?>
        <div class="db-grid-wrap db-result">
            <table class="db-grid">
                <thead><tr><th class="db-gut"></th><?php foreach ($panel['columns'] as $name): ?><th><?= $e($name) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php foreach ($panel['rows'] as $n => $cells): ?>
                    <tr>
                        <td class="db-gut"><span class="db-rownum"><?= $n + 1 ?></span></td>
                        <?php foreach ($cells as $cell): ?>
                        <td<?= $cell['title'] !== '' ? ' title="' . $e($cell['title']) . '"' : '' ?>><?php if ($cell['kind'] !== 'text'): ?><span class="db-null"><?= $e($cell['text']) ?></span><?php else: ?><?= $e($cell['text']) ?><?php endif; ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($backup_link !== null): ?>
        <p class="db-done"><a href="<?= $e($backup_link) ?>">Backed up first</a>, restore it under Backups.</p>
    <?php endif; ?>
<?php endif; ?>
</div>
