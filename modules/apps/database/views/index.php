<?php
/**
 * apps/{id}/database (Database::index). Expects: $app, $urls, $live_state, $can_rollback, $database_name,
 * $tables (App_database::tables), $table (one of $tables or null), $columns, $foreign_keys (App_database::foreign_keys),
 * $where (the column => value filter on $rows), $rows, $more, $page,
 * $sql, $results (App_database::run or null), $query_error, $error (the database cannot be opened),
 * $recent_actions (Database_model::recent_actions), $backups (Database_model::backups), $backup_id (the
 * backup taken before $sql ran, or null).
 */
$e = fn($value) => htmlspecialchars((string) $value);
$base = 'apps/' . (int) $app->id . '/database';
$table_url = fn(string $name, array $extra = []) => $base . '?' . http_build_query(['table' => $name] + $extra);
$size = function (?int $bytes): string {
    if ($bytes === null) {
        return '';
    }

    foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
        if ($bytes < 1024 || $unit === 'GB') {
            return ($unit === 'B' ? $bytes : number_format($bytes, 1)) . ' ' . $unit;
        }

        $bytes /= 1024;
    }

    return '';
};
// One cell: NULL, a binary value's size, or text cut to fit
$cell = function ($value) use ($e): string {
    if ($value === null) {
        return '<span class="db-null">NULL</span>';
    }

    $value = (string) $value;

    if (!App_database::is_text($value)) {
        return '<span class="db-null">' . strlen($value) . ' bytes</span>';
    }

    $short = preg_replace('/^(.{80}).+$/us', '$1…', $value);

    return $short === $value ? $e($value) : '<span title="' . $e(preg_replace('/^(.{1000}).+$/us', '$1…', $value)) . '">' . $e($short) . '</span>';
};
// A foreign key's target: the referenced row, or the rows matching its values
$reference_url = fn(array $reference) => isset($reference['key'])
    ? $base . '/row?' . http_build_query($reference)
    : $base . '?' . http_build_query($reference);
$references = []; // column => foreign keys it is part of
foreach ($foreign_keys as $foreign_key) {
    foreach ($foreign_key['columns'] as $name) {
        $references[$name][] = $foreign_key;
    }
}
$page_url = fn(int $to) => $table_url($table['name'] ?? '', ['page' => $to] + ($where === [] ? [] : ['where' => $where]));
$primary_key = $table !== null ? App_database::primary_key($columns) : [];
$row_key = fn(array $row) => array_combine($primary_key, array_map(fn($c) => (string) $row[$c], $primary_key));
// Columns whose cells offer Change password: named like one, or holding a password hash
$password_columns = $primary_key === [] ? [] : array_column(array_filter($columns, fn($column) => App_password::is_password_column($column)
    || (App_password::can_hold_hash($column) && array_filter(array_column($rows, $column['name']), [App_password::class, 'is_hash']) !== [])), 'name');
$password_url = fn(array $row, string $column) => $base . '/row/password?' . http_build_query(['table' => $table['name'], 'key' => $row_key($row), 'column' => $column]);
?>
<div class="ov">
    <?php $active_tab = 'database'; require APPPATH . 'modules/apps/views/_app_header.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <?php if ($error !== null): ?>
        <section class="ov-card">
            <p class="ov-empty"><?= $e($error) ?></p>
        </section>
    <?php else: ?>
    <div class="db-main">
        <aside class="ov-card db-tables" aria-labelledby="db-tables-h">
            <div class="ov-card-head">
                <h2 id="db-tables-h">Tables</h2>
                <span class="muted"><?= count($tables) ?></span>
            </div>
            <?php if ($tables === []): ?>
                <p class="ov-empty muted">No tables yet. Your app creates them, or run a <code>CREATE TABLE</code> here.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($tables as $t): ?>
                    <li>
                        <a href="<?= $e($table_url($t['name'])) ?>"<?= $table !== null && $table['name'] === $t['name'] ? ' aria-current="page"' : '' ?>>
                            <span class="ov-mono"><?= $e($t['name']) ?></span>
                            <span class="muted"><?= $t['view'] ? 'view' : $e($size($t['bytes'])) ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </aside>

        <div class="ov-col">
            <section class="ov-card" aria-labelledby="db-sql-h">
                <div class="ov-card-head">
                    <h2 id="db-sql-h">SQL</h2>
                    <span class="muted ov-mono"><?= $e($database_name) ?></span>
                </div>
                <p class="db-note"><strong>Tip:</strong> deploys never change your tables. Run your modules' <code>.sql</code> files and later schema changes here, with changes allowed and Back up first ticked.</p>
                <?= form_open($base . '/query' . ($table !== null ? '?' . http_build_query(['table' => $table['name']]) : ''), [
                    'class' => 'ov-pad db-sql',
                    'onsubmit' => "return !this.allow_changes.checked || confirm(this.backup.checked"
                        . " ? 'Run this SQL with changes allowed? The tables it names are backed up first, so you can restore them under Backups.'"
                        . " : 'Run this SQL with changes allowed and no backup? It can change or delete data in " . $e($database_name) . " for good.')",
                ]) ?>
                    <textarea name="sql" id="db-sql" aria-label="SQL statements" class="form-input mono" rows="4" spellcheck="false" placeholder="SELECT * FROM <?= $e($table['name'] ?? 'users') ?> LIMIT 10"><?= $e($sql) ?></textarea>
                    <div class="db-sql-foot">
                        <span class="form-hint">Separate statements with <code>;</code>. They run in one transaction as your app's own database user, so if one fails the others are rolled back. CREATE, ALTER, DROP, TRUNCATE and RENAME commit at once, with everything before them. Read-only unless you allow changes. Stops after <?= App_database::STATEMENT_SECONDS ?> seconds. Everything is logged.</span>
                        <span class="db-allow">
                            <label class="checkbox-label">
                                <input type="checkbox" name="allow_changes" value="1" id="db-allow-changes" onchange="this.form.backup.disabled = !this.checked">
                                Allow changes
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="backup" value="1" id="db-backup" checked disabled>
                                Back up first
                            </label>
                        </span>
                        <button type="submit" class="btn-primary">Run</button>
                    </div>
                <?= form_close() ?>

                <?php if ($query_error !== null): ?>
                    <p class="db-error" role="alert"><?= $e($query_error) ?></p>
                <?php elseif ($results !== null): ?>
                    <?php if (count($results) > 1): ?>
                        <p class="db-done" role="status"><?= count($results) ?> statements ran in one transaction in <?= number_format(array_sum(array_column($results, 'seconds')), 2) ?> s.</p>
                    <?php endif; ?>
                    <?php foreach ($results as $i => $result): ?>
                    <p class="db-done"<?= count($results) === 1 ? ' role="status"' : '' ?>>
                        <?php if (count($results) > 1): ?>
                            <span class="db-statement"><?= $i + 1 ?>. <code title="<?= $e($result['statement']) ?>"><?= $e(preg_replace('/^(.{80}).+$/us', '$1…', preg_replace('/\s+/', ' ', $result['statement']))) ?></code></span>
                        <?php endif; ?>
                        <?php if ($result['affected'] !== null): ?>
                            <?= count($results) === 1 ? 'Done. ' : '' ?><?= (int) $result['affected'] ?> <?= $result['affected'] === 1 ? 'row' : 'rows' ?> changed in <?= number_format($result['seconds'], 2) ?> s.
                        <?php else: ?>
                            <?= count($result['rows']) ?> <?= count($result['rows']) === 1 ? 'row' : 'rows' ?> in <?= number_format($result['seconds'], 2) ?> s<?= $result['truncated'] ? ', only the first ' . count($result['rows']) . ' shown' : '' ?>.
                        <?php endif; ?>
                    </p>
                    <?php if ($result['affected'] === null): ?>
                    <div class="db-grid-wrap">
                        <table class="db-grid">
                            <thead><tr><?php foreach ($result['columns'] as $name): ?><th><?= $e($name) ?></th><?php endforeach; ?></tr></thead>
                            <tbody>
                                <?php foreach ($result['rows'] as $row): ?>
                                <tr><?php foreach ($row as $value): ?><td><?= $cell($value) ?></td><?php endforeach; ?></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($backup_id !== null): ?>
                        <p class="db-done"><a href="#backup-<?= (int) $backup_id ?>">Backed up first</a>, restore it under Backups.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php if ($table !== null): ?>
            <section class="ov-card" aria-labelledby="db-table-h">
                <div class="ov-card-head">
                    <h2 id="db-table-h" class="ov-mono"><?= $e($table['name']) ?></h2>
                    <span class="ov-links">
                        <?php if ($table['rows'] !== null): ?><span class="muted">about <?= number_format($table['rows']) ?> rows</span><?php endif; ?>
                        <?php if (!$table['view']): ?><a href="<?= $e($base . '/row?' . http_build_query(['table' => $table['name']])) ?>">Add row</a><?php endif; ?>
                    </span>
                </div>
                <?php if ($where !== []): ?>
                    <p class="db-note">
                        Rows where <?= implode(' and ', array_map(fn($column, $value) => '<span class="ov-mono">' . $e($column) . ' = ' . $e($value) . '</span>', array_keys($where), $where)) ?>.
                        <a href="<?= $e($table_url($table['name'])) ?>">Show all rows</a>
                    </p>
                <?php endif; ?>
                <?php if ($password_columns !== []): ?>
                    <p class="db-note">Right-click a value in <?= implode(', ', array_map(fn($name) => '<span class="ov-mono">' . $e($name) . '</span>', $password_columns)) ?> to change the password, or use the button in the row's form.</p>
                <?php endif; ?>
                <?php if (!$table['view'] && $primary_key === []): ?>
                    <p class="db-note">This table has no primary key, so its rows can only be changed with SQL.</p>
                <?php endif; ?>
                <?php if ($rows === []): ?>
                    <p class="ov-empty muted"><?= $page > 1 ? 'No more rows.' : ($where !== [] ? 'No matching rows.' : 'No rows yet.') ?></p>
                <?php else: ?>
                <div class="db-grid-wrap">
                    <table class="db-grid"<?= $password_columns !== [] ? ' data-password-modal="' . $e(Database::PASSWORD_MODAL) . '"' : '' ?>>
                        <thead>
                            <tr>
                                <?php if ($primary_key !== []): ?><th aria-label="Actions"></th><?php endif; ?>
                                <?php foreach ($columns as $column): ?>
                                <th title="<?= $e($column['column_type']) ?>"><?= $e($column['name']) ?><?= $column['key'] ? ' <span class="db-key">key</span>' : '' ?><?php foreach ($references[$column['name']] ?? [] as $foreign_key): ?> <span class="db-key">&rarr; <?= $e($foreign_key['table']) ?></span><?php endforeach; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php if ($primary_key !== []): ?>
                                <td><a href="<?= $e($base . '/row?' . http_build_query(['table' => $table['name'], 'key' => $row_key($row)])) ?>" data-edit-row>Edit</a></td>
                                <?php endif; ?>
                                <?php foreach ($columns as $column): ?>
                                <?php $reference = isset($references[$column['name']]) ? App_database::reference($references[$column['name']][0], $row) : null; ?>
                                <td<?php if (in_array($column['name'], $password_columns, true)): ?> class="db-password-cell" data-password-form="<?= $e($password_url($row, $column['name'])) ?>" title="Right-click to change the password"<?php endif; ?>><?php if ($reference !== null): ?><a href="<?= $e($reference_url($reference)) ?>" class="db-ref" title="Open in <?= $e($reference['table']) ?>"><?= $cell($row[$column['name']]) ?></a><?php else: ?><?= App_database::is_binary($column) && !$column['key'] && $row[$column['name']] !== null ? '<span class="db-null">' . (int) $row[$column['name']] . ' bytes</span>' : $cell($row[$column['name']] ?? null) ?><?php endif; ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php if ($page > 1 || $more): ?>
                <nav class="db-pages" aria-label="Pages">
                    <?php if ($page > 1): ?><a href="<?= $e($page_url($page - 1)) ?>">&larr; Previous</a><?php else: ?><span></span><?php endif; ?>
                    <span class="muted">Page <?= $page ?></span>
                    <?php if ($more): ?><a href="<?= $e($page_url($page + 1)) ?>">Next &rarr;</a><?php else: ?><span></span><?php endif; ?>
                </nav>
                <?php endif; ?>
            </section>
            <?php elseif ($results === null && $query_error === null && $tables !== []): ?>
                <p class="muted">Pick a table to see its rows.</p>
            <?php endif; ?>

            <?php if ($backups !== []): ?>
            <section class="ov-card" aria-labelledby="db-backups-h">
                <div class="ov-card-head">
                    <h2 id="db-backups-h">Backups</h2>
                    <span class="muted">kept <?= Database_model::BACKUP_DAYS ?> days</span>
                </div>
                <ul class="db-log db-backups">
                    <?php foreach ($backups as $backup):
                        $taken = substr((string) $backup->created_at, 0, 16);
                    ?>
                    <li id="backup-<?= (int) $backup->id ?>"<?= $backup_id !== null && (int) $backup->id === (int) $backup_id ? ' class="db-backup-new"' : '' ?>>
                        <span class="muted"><?= $e($taken) ?> &middot; <?= $e($backup->who ?? 'someone') ?> &middot; <?= number_format((int) $backup->rows) ?> <?= (int) $backup->rows === 1 ? 'row' : 'rows' ?>, <?= $e($size((int) $backup->bytes)) ?></span>
                        <span class="db-backup-what">
                            <strong class="ov-mono"><?= $e($backup->summary) ?></strong>
                            <?php if ($backup->statement !== null): ?>
                                <span class="muted">before</span> <code title="<?= $e($backup->statement) ?>"><?= $e(preg_replace('/^(.{100}).+$/us', '$1…', preg_replace('/\s+/', ' ', (string) $backup->statement))) ?></code>
                            <?php endif; ?>
                            <?php if ($backup->error !== null): ?><span class="db-log-error">(that failed, so nothing changed)</span><?php endif; ?>
                        </span>
                        <?= form_open($base . '/backups/restore', [
                            'class' => 'db-restore',
                            'onsubmit' => "return confirm(" . json_encode('Restore ' . $backup->summary . ' as it was at ' . $taken . ' UTC? Changes made to it since then are lost.', JSON_INVALID_UTF8_SUBSTITUTE) . ")",
                        ]) ?>
                            <input type="hidden" name="backup" value="<?= (int) $backup->id ?>">
                            <?php if ($backup->restored_at !== null): ?><span class="muted">restored <?= $e(substr((string) $backup->restored_at, 5, 11)) ?></span><?php endif; ?>
                            <button type="submit" class="btn-secondary">Restore</button>
                        <?= form_close() ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($recent_actions !== []): ?>
            <section class="ov-card" aria-labelledby="db-log-h">
                <div class="ov-card-head">
                    <h2 id="db-log-h">Recent activity</h2>
                    <span class="muted">times in UTC</span>
                </div>
                <ul class="db-log">
                    <?php foreach ($recent_actions as $entry): ?>
                    <li>
                        <span class="muted"><?= $e(substr((string) $entry->created_at, 0, 16)) ?> &middot; <?= $e($entry->who ?? 'someone') ?></span>
                        <code title="<?= $e($entry->statement) ?>"><?= $e(preg_replace('/^(.{140}).+$/us', '$1…', preg_replace('/\s+/', ' ', (string) $entry->statement))) ?></code>
                        <span class="<?= $entry->error !== null ? 'db-log-error' : 'muted' ?>"><?= $entry->error !== null ? $e($entry->error) : ($entry->affected !== null ? (int) $entry->affected . ($entry->action === 'restore' ? ' restored' : ' changed') : 'read') ?><?php if ($entry->backup_id !== null): ?> &middot; <a href="#backup-<?= (int) $entry->backup_id ?>">backup</a><?php endif; ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
