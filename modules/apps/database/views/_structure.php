<?php
/**
 * The Structure view of views/index.php: the open table's columns.
 * Expects (Database::_workspace_data): $structure (name, type, nullable, default,
 * is_key, extra, references), $view_toggle, $sql_url.
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div class="db-rows">
    <div class="db-grid-wrap">
        <table class="db-grid db-structure">
            <thead>
                <tr>
                    <th>Column</th>
                    <th>Type</th>
                    <th>Null</th>
                    <th>Default</th>
                    <th>Extra</th>
                    <th>Points at</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($structure as $column): ?>
                <tr>
                    <td><?php if ($column['is_key']): ?><span class="db-key" title="Primary key">&#9911;</span> <?php endif; ?><?= $e($column['name']) ?></td>
                    <td><?= $e($column['type']) ?></td>
                    <td><?= $e($column['nullable']) ?></td>
                    <td><?php if ($column['default'] === null): ?><span class="db-null">NULL</span><?php else: ?><?= $e($column['default']) ?><?php endif; ?></td>
                    <td><?= $e($column['extra']) ?></td>
                    <td><?= $e($column['references']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="db-status">
        <?php require __DIR__ . '/_toggle.php'; ?>
        <span class="db-status-text"><?= count($structure) ?> <?= count($structure) === 1 ? 'column' : 'columns' ?></span>
    </div>
</div>
