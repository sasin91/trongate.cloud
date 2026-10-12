<?php
/**
 * The Schema view of views/index.php: how the database differs from the
 * schema.sql in the app's repository. Each difference is two rows, what
 * schema.sql says and what the database has.
 *
 * Expects (App_database_schema::_schema_data): $schema_message (why there is
 * nothing to compare, or null), $schema_source and $schema_source_href,
 * $schema_differences (table, table_href, kind, name, change, change_class, schema,
 * schema_missing, database, database_missing), $schema_summary, $schema_skipped.
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div class="db-rows">
    <?php if ($schema_message !== null): ?>
        <div class="db-empty">
            <p><?= $e($schema_message) ?></p>
        </div>
    <?php elseif ($schema_differences === []): ?>
        <div class="db-empty">
            <p>Your database matches <a href="<?= $e($schema_source_href) ?>" target="_blank" rel="noopener"><?= $e($schema_source) ?></a>.</p>
        </div>
    <?php else: ?>
    <div class="db-grid-wrap">
        <table class="db-grid db-diff">
            <thead>
                <tr>
                    <th>Table</th>
                    <th>What</th>
                    <th>Change</th>
                    <th>From</th>
                    <th>Definition</th>
                </tr>
            </thead>
            <?php foreach ($schema_differences as $difference): ?>
            <tbody class="db-diff-pair">
                <tr>
                    <td rowspan="2">
                        <?php if ($difference['table_href'] !== null): ?>
                            <a href="<?= $e($difference['table_href']) ?>" class="db-ref"><?= $e($difference['table']) ?></a>
                        <?php else: ?>
                            <?= $e($difference['table']) ?>
                        <?php endif; ?>
                    </td>
                    <td rowspan="2"><?= $e($difference['kind']) ?><?php if ($difference['name'] !== ''): ?> <strong><?= $e($difference['name']) ?></strong><?php endif; ?></td>
                    <td rowspan="2"><span class="db-diff-change <?= $e($difference['change_class']) ?>"><?= $e($difference['change']) ?></span></td>
                    <td class="db-diff-side">schema.sql</td>
                    <td<?= $difference['schema_missing'] ? ' class="db-null"' : '' ?>><?= $e($difference['schema']) ?></td>
                </tr>
                <tr>
                    <td class="db-diff-side">Database</td>
                    <td<?= $difference['database_missing'] ? ' class="db-null"' : '' ?>><?= $e($difference['database']) ?></td>
                </tr>
            </tbody>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="db-status">
        <?php if ($schema_source !== null): ?>
            <a href="<?= $e($schema_source_href) ?>" target="_blank" rel="noopener"><?= $e($schema_source) ?></a>
        <?php endif; ?>
        <span class="db-status-text"><?= $e($schema_summary) ?><?php if ($schema_skipped !== null): ?> &middot; <?= $e($schema_skipped) ?><?php endif; ?></span>
        <span class="muted">Read-only &middot; run changes in <a href="<?= $e($sql_url) ?>">SQL</a></span>
    </div>
</div>
