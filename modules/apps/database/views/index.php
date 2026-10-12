<?php
/**
 * apps/{id}/database (Database::index): the workspace, laid out like TablePlus.
 * A sidebar of tables, tabs along the top, and one view in the middle:
 * data (views/_data.php), structure (views/_structure.php), diagram (views/_diagram.php), SQL with its
 * result, history and backups (views/_sql.php), or how the database differs from schema.sql (views/_schema.php).
 *
 * Expects plain values from Database::_workspace_data: $app, $urls, $live_state,
 * $can_rollback, $base, $database_name, $view, $error, $table_name,
 * $sidebar_tables and $sidebar_views (name, href, note, is_current), $open_tabs
 * (href, label, icon, is_current, close_href), $view_toggle ([href, label, is_current]),
 * $sql_url, $history_url, $backups_url, $backup_entries, $copy_tables_url (null hides
 * Copy production's tables), and what the partials list.
 */
$e = fn($value) => htmlspecialchars((string) $value);
$is_sql_view = in_array($view, ['sql', 'history', 'backups'], true);
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
    <svg class="db-icons" aria-hidden="true">
        <symbol id="db-icon-table" viewBox="0 0 16 16"><rect x="1.5" y="2.5" width="13" height="11" rx="2" fill="none" stroke="currentColor"/><path d="M1.5 6.5h13M6 6.5v7" stroke="currentColor"/></symbol>
        <symbol id="db-icon-view" viewBox="0 0 16 16"><path d="M1 8s2.5-5 7-5 7 5 7 5-2.5 5-7 5-7-5-7-5z" fill="none" stroke="currentColor"/><circle cx="8" cy="8" r="2" fill="currentColor"/></symbol>
        <symbol id="db-icon-sql" viewBox="0 0 16 16"><path d="M5 4 1.5 8 5 12M11 4l3.5 4L11 12" fill="none" stroke="currentColor" stroke-width="1.4"/></symbol>
        <symbol id="db-icon-schema" viewBox="0 0 16 16"><path d="M2 4.5h7M2 8h12M2 11.5h7M12 2.5v4M10 4.5h4M10 11.5h4" fill="none" stroke="currentColor" stroke-width="1.3"/></symbol>
    </svg>

    <div class="db-ws">
        <div class="db-bar">
            <span class="db-conn" title="Your app's database"><span class="db-conn-dot"></span><?= $e($database_name) ?> &middot; MariaDB</span>
            <nav class="db-tabs" aria-label="Open tables">
                <?php foreach ($open_tabs as $tab): ?>
                <span class="db-tab"<?= $tab['is_current'] ? ' aria-current="page"' : '' ?>>
                    <a href="<?= $e($tab['href']) ?>"><svg><use href="#db-icon-<?= $e($tab['icon']) ?>"/></svg><?= $e($tab['label']) ?></a>
                    <?php if ($tab['close_href'] !== null): ?>
                        <a href="<?= $e($tab['close_href']) ?>" class="db-tab-close" aria-label="Close <?= $e($tab['label']) ?>">&times;</a>
                    <?php endif; ?>
                </span>
                <?php endforeach; ?>
            </nav>
        </div>

        <aside class="db-side db-tables" aria-label="Tables">
            <input type="search" class="db-search" placeholder="Search tables…" aria-label="Search tables" data-table-search>
            <div class="db-side-list">
                <p class="db-group">Tables &middot; <?= count($sidebar_tables) ?></p>
                <?php if ($sidebar_tables === []): ?>
                    <?php if ($copy_tables_url !== null): ?>
                    <?= form_open($copy_tables_url, ['class' => 'db-side-empty']) ?>
                        <p>No tables yet. Start from production's tables, without their rows.</p>
                        <button type="submit" class="btn-secondary">Copy production's tables</button>
                    <?= form_close() ?>
                    <?php else: ?>
                    <p class="db-side-empty">No tables yet. Your app creates them, or run a <code>CREATE TABLE</code> in <a href="<?= $e($sql_url) ?>">SQL</a>.</p>
                    <?php endif; ?>
                <?php endif; ?>
                <ul>
                    <?php foreach ($sidebar_tables as $item): ?>
                    <li data-table-name="<?= $e($item['name']) ?>">
                        <a href="<?= $e($item['href']) ?>" data-keep-scroll<?= $item['is_current'] ? ' aria-current="page"' : '' ?>>
                            <svg><use href="#db-icon-table"/></svg>
                            <span class="db-side-name"><?= $e($item['name']) ?></span>
                            <span class="db-side-note"><?= $e($item['note']) ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($sidebar_views !== []): ?>
                <p class="db-group">Views</p>
                <ul>
                    <?php foreach ($sidebar_views as $item): ?>
                    <li data-table-name="<?= $e($item['name']) ?>">
                        <a href="<?= $e($item['href']) ?>" data-keep-scroll<?= $item['is_current'] ? ' aria-current="page"' : '' ?>>
                            <svg><use href="#db-icon-view"/></svg>
                            <span class="db-side-name"><?= $e($item['name']) ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <div class="db-side-foot">
                <a href="<?= $e($history_url) ?>"<?= $view === 'history' ? ' aria-current="page"' : '' ?>>History</a>
                <a href="<?= $e($backups_url) ?>"<?= $view === 'backups' ? ' aria-current="page"' : '' ?>>Backups &middot; <?= count($backup_entries) ?></a>
            </div>
        </aside>

        <div class="db-pick">
            <select aria-label="Open a table" onchange="window.location.href = this.value">
                <option value="<?= $e(BASE_URL . $sql_url) ?>"<?= $table_name === null ? ' selected' : '' ?>>Pick a table…</option>
                <?php foreach ([...$sidebar_tables, ...$sidebar_views] as $item): ?>
                    <option value="<?= $e(BASE_URL . $item['href']) ?>"<?= $item['is_current'] ? ' selected' : '' ?>><?= $e($item['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <section class="db-body">
            <?php if ($is_sql_view): ?>
                <?php require __DIR__ . '/_sql.php'; ?>
            <?php elseif ($view === 'schema'): ?>
                <?php require __DIR__ . '/_schema.php'; ?>
            <?php elseif ($table_name === null): ?>
                <div class="db-empty">
                    <p>Pick a table on the left to see its rows, or run SQL.</p>
                    <a href="<?= $e($sql_url) ?>" class="btn-secondary">Open SQL</a>
                </div>
            <?php elseif ($view === 'structure'): ?>
                <?php require __DIR__ . '/_structure.php'; ?>
            <?php elseif ($view === 'diagram' && $diagram !== null): ?>
                <?php require __DIR__ . '/_diagram.php'; ?>
            <?php else: ?>
                <?php require __DIR__ . '/_data.php'; ?>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</div>
