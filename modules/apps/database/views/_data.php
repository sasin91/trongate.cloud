<?php
/**
 * The Data view of views/index.php: the filter bar, the grid, and the status bar.
 * Ticking rows (select.js) and Delete selected need the grid and the status
 * bar in one form, so the form wraps both when rows can be ticked.
 *
 * Expects (Database::_workspace_data): $base, $table_name, $filter, $grid_columns,
 * $grid_rows (number, tick_value, edit_url, cells), $status, $password_columns,
 * $tick_values, $delete_rows_url, $delete_rows_confirm_url, $view_toggle, $sql_url.
 */
$e = fn($value) => htmlspecialchars((string) $value);
$can_tick = $tick_values !== [];
?>
<form method="get" action="<?= $e(BASE_URL . $base) ?>" class="db-filter">
    <input type="hidden" name="table" value="<?= $e($table_name) ?>">
    <select name="filter_column" aria-label="Filter column" class="db-control">
        <option value="">Column…</option>
        <?php foreach ($filter['columns'] as $name): ?>
            <option value="<?= $e($name) ?>"<?= $name === $filter['column'] ? ' selected' : '' ?>><?= $e($name) ?></option>
        <?php endforeach; ?>
    </select>
    <span class="db-filter-op">=</span>
    <input type="text" name="filter_value" value="<?= $e($filter['value']) ?>" aria-label="Filter value" placeholder="value" class="db-control db-filter-value">
    <button type="submit" class="db-button">Apply</button>
    <?php if ($filter['active'] !== []): ?>
        <span class="db-filter-active">
            Showing rows where <span class="ov-mono"><?= $e(implode(' and ', $filter['active'])) ?></span>
            <a href="<?= $e($filter['clear_href']) ?>">Show all</a>
        </span>
    <?php endif; ?>
</form>

<?php if ($can_tick): ?>
<?= form_open($delete_rows_url, ['id' => 'db-rows-form', 'class' => 'db-rows', 'data-delete-rows' => '']) ?>
<?php else: ?>
<div class="db-rows">
<?php endif; ?>

    <div class="db-grid-wrap">
        <?php if ($grid_rows === []): ?>
            <p class="db-empty-rows"><?= $status['page'] > 1 ? 'No more rows.' : ($filter['active'] !== [] ? 'No matching rows.' : 'No rows yet.') ?></p>
        <?php else: ?>
        <table class="db-grid"<?= $password_columns !== [] ? ' data-password-modal="' . $e(Database::PASSWORD_MODAL) . '"' : '' ?>>
            <thead>
                <tr>
                    <th class="db-gut">
                        <?php if ($can_tick): ?><input type="checkbox" class="db-tick" aria-label="Select all rows on this page" data-tick-all><?php endif; ?>
                    </th>
                    <?php foreach ($grid_columns as $column): ?>
                    <th title="<?= $e($column['type']) ?>">
                        <span class="db-col-name"><?php if ($column['is_key']): ?><span class="db-key" title="Primary key">&#9911;</span> <?php endif; ?><?= $e($column['name']) ?><?php foreach ($column['references'] as $referenced): ?> <span class="db-fk">&rarr; <?= $e($referenced) ?></span><?php endforeach; ?></span>
                        <span class="db-col-type"><?= $e($column['type']) ?></span>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grid_rows as $row): ?>
                <tr>
                    <td class="db-gut">
                        <?php if ($row['tick_value'] !== null): ?><input type="checkbox" name="rows[]" value="<?= $e($row['tick_value']) ?>" class="db-tick" aria-label="Select row <?= (int) $row['number'] ?>" data-tick-row><?php endif; ?>
                        <?php if ($row['edit_url'] !== null): ?>
                            <button type="button" class="db-rownum" title="Edit row" mx-get="<?= $e($row['edit_url']) ?>" mx-build-modal="<?= $e(Database::ROW_MODAL) ?>" data-edit-row><?= (int) $row['number'] ?></button>
                        <?php else: ?>
                            <span class="db-rownum"><?= (int) $row['number'] ?></span>
                        <?php endif; ?>
                    </td>
                    <?php foreach ($row['cells'] as $cell): ?>
                    <td<?= $cell['class'] !== '' ? ' class="' . $cell['class'] . '"' : '' ?><?php if ($cell['password_url'] !== null): ?> data-password-form="<?= $e($cell['password_url']) ?>"<?php endif; ?><?= $cell['title'] !== '' ? ' title="' . $e($cell['title']) . '"' : '' ?>>
                        <?php if ($cell['href'] !== null): ?>
                            <a href="<?= $e($cell['href']) ?>" class="db-ref" title="Open the row it points at"><?= $e($cell['text']) ?></a>
                        <?php elseif ($cell['kind'] !== 'text'): ?>
                            <span class="db-null"><?= $e($cell['text']) ?></span>
                        <?php else: ?>
                            <?= $e($cell['text']) ?>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="db-status">
        <?php require __DIR__ . '/_toggle.php'; ?>
        <?php if ($status['add_row_url'] !== null): ?>
            <button type="button" class="db-button" mx-get="<?= $e($status['add_row_url']) ?>" mx-build-modal="<?= $e(Database::ADD_ROW_MODAL) ?>">+ Row</button>
        <?php endif; ?>
        <?php if ($can_tick): ?>
            <button type="button" class="db-button db-button-danger" data-delete-selected data-confirm-url="<?= $e($delete_rows_confirm_url) ?>" mx-get="<?= $e($delete_rows_confirm_url) ?>" mx-build-modal="<?= $e(Database::DELETE_ROWS_MODAL) ?>">Delete selected</button>
        <?php endif; ?>
        <span class="db-status-text">
            <?= $e($status['summary']) ?>
            <?php if ($status['note'] !== null): ?>&middot; <?= $e($status['note']) ?><?php endif; ?>
        </span>
        <span class="db-pager">
            <?php if ($status['previous_href'] !== null): ?>
                <a href="<?= $e($status['previous_href']) ?>" class="db-button" aria-label="Previous page">&lsaquo;</a>
            <?php else: ?>
                <span class="db-button" aria-hidden="true">&lsaquo;</span>
            <?php endif; ?>
            <span>Page <?= (int) $status['page'] ?></span>
            <?php if ($status['next_href'] !== null): ?>
                <a href="<?= $e($status['next_href']) ?>" class="db-button" aria-label="Next page">&rsaquo;</a>
            <?php else: ?>
                <span class="db-button" aria-hidden="true">&rsaquo;</span>
            <?php endif; ?>
        </span>
    </div>

<?php if ($can_tick): ?>
<?= form_close() ?>
<?php else: ?>
</div>
<?php endif; ?>
