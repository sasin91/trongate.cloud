<?php
/**
 * apps/{id}/database (Database::index). Expects: $app, $urls, $live_state, $can_rollback, $database_name,
 * $tables (App_database::tables), $table (one of $tables or null), $columns, $rows, $more, $page,
 * $sql, $result (App_database::run or null), $query_error, $error (the database cannot be opened),
 * $recent_actions (Database_model::recent_actions).
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
$primary_key = $table !== null ? App_database::primary_key($columns) : [];
$row_key = fn(array $row) => array_combine($primary_key, array_map(fn($c) => (string) $row[$c], $primary_key));
?>
<div class="ov">
    <?php $active_tab = 'database'; require APPPATH . 'modules/apps/views/_app_header.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>

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
                <?= form_open($base . '/query' . ($table !== null ? '?' . http_build_query(['table' => $table['name']]) : ''), [
                    'class' => 'ov-pad db-sql',
                    'onsubmit' => "return !this.allow_changes.checked || confirm('Run this statement with changes allowed? It can change or delete data in " . $e($database_name) . ".')",
                ]) ?>
                    <textarea name="sql" id="db-sql" aria-label="SQL statement" class="form-input mono" rows="4" spellcheck="false" placeholder="SELECT * FROM <?= $e($table['name'] ?? 'users') ?> LIMIT 10"><?= $e($sql) ?></textarea>
                    <div class="db-sql-foot">
                        <span class="form-hint">One statement at a time, as your app's own database user. Read-only unless you allow changes. Stops after <?= App_database::STATEMENT_SECONDS ?> seconds. Every statement is logged.</span>
                        <label class="checkbox-label db-allow">
                            <input type="checkbox" name="allow_changes" value="1" id="db-allow-changes">
                            Allow changes
                        </label>
                        <button type="submit" class="btn-primary">Run</button>
                    </div>
                <?= form_close() ?>

                <?php if ($query_error !== null): ?>
                    <p class="db-error" role="alert"><?= $e($query_error) ?></p>
                <?php elseif ($result !== null && $result['affected'] !== null): ?>
                    <p class="db-done" role="status">Done. <?= (int) $result['affected'] ?> <?= $result['affected'] === 1 ? 'row' : 'rows' ?> changed in <?= number_format($result['seconds'], 2) ?> s.</p>
                <?php elseif ($result !== null): ?>
                    <p class="db-done" role="status">
                        <?= count($result['rows']) ?> <?= count($result['rows']) === 1 ? 'row' : 'rows' ?> in <?= number_format($result['seconds'], 2) ?> s<?= $result['truncated'] ? ', only the first ' . App_database::MAX_RESULT_ROWS . ' shown' : '' ?>.
                    </p>
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
                <?php if (!$table['view'] && $primary_key === []): ?>
                    <p class="db-note">This table has no primary key, so its rows can only be changed with SQL.</p>
                <?php endif; ?>
                <?php if ($rows === []): ?>
                    <p class="ov-empty muted"><?= $page > 1 ? 'No more rows.' : 'No rows yet.' ?></p>
                <?php else: ?>
                <div class="db-grid-wrap">
                    <table class="db-grid">
                        <thead>
                            <tr>
                                <?php if ($primary_key !== []): ?><th aria-label="Actions"></th><?php endif; ?>
                                <?php foreach ($columns as $column): ?>
                                <th title="<?= $e($column['column_type']) ?>"><?= $e($column['name']) ?><?= $column['key'] ? ' <span class="db-key">key</span>' : '' ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php if ($primary_key !== []): ?>
                                <td><a href="<?= $e($base . '/row?' . http_build_query(['table' => $table['name'], 'key' => $row_key($row)])) ?>">Edit</a></td>
                                <?php endif; ?>
                                <?php foreach ($columns as $column): ?>
                                <td><?= App_database::is_binary($column) && !$column['key'] && $row[$column['name']] !== null ? '<span class="db-null">' . (int) $row[$column['name']] . ' bytes</span>' : $cell($row[$column['name']] ?? null) ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php if ($page > 1 || $more): ?>
                <nav class="db-pages" aria-label="Pages">
                    <?php if ($page > 1): ?><a href="<?= $e($table_url($table['name'], ['page' => $page - 1])) ?>">&larr; Previous</a><?php else: ?><span></span><?php endif; ?>
                    <span class="muted">Page <?= $page ?></span>
                    <?php if ($more): ?><a href="<?= $e($table_url($table['name'], ['page' => $page + 1])) ?>">Next &rarr;</a><?php else: ?><span></span><?php endif; ?>
                </nav>
                <?php endif; ?>
            </section>
            <?php elseif ($result === null && $query_error === null && $tables !== []): ?>
                <p class="muted">Pick a table to see its rows.</p>
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
                        <span class="<?= $entry->error !== null ? 'db-log-error' : 'muted' ?>"><?= $entry->error !== null ? $e($entry->error) : ($entry->affected !== null ? (int) $entry->affected . ' changed' : 'read') ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
