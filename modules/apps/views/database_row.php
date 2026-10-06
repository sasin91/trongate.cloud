<?php
/**
 * apps/{id}/database/row: edit one row ($key set) or add one ($key empty).
 * Expects: $app, $table, $columns (App_database::columns), $key, $row (values, or null for a
 * new row), $error, $back_url.
 */
$e = fn($value) => htmlspecialchars((string) $value);
$is_new = $key === [];
$base = 'apps/' . (int) $app->id . '/database';
?>
<div class="content narrow">
    <div class="back-link">
        <a href="<?= $e($back_url) ?>" class="nav-link">&larr; Back to <span class="ov-mono"><?= $e($table) ?></span></a>
    </div>

    <h1><?= $is_new ? 'Add row' : 'Edit row' ?></h1>

    <?php if ($error !== null): ?>
        <p class="db-error" role="alert"><?= $e($error) ?></p>
    <?php endif; ?>

    <div class="card settings-card">
        <?= form_open($base . '/row/submit') ?>
        <input type="hidden" name="table" value="<?= $e($table) ?>">
        <?php foreach ($key as $name => $value): ?>
            <input type="hidden" name="key[<?= $e($name) ?>]" value="<?= $e($value) ?>">
        <?php endforeach; ?>

        <?php foreach ($columns as $i => $column):
            $name = $column['name'];
            $value = $row !== null && array_key_exists($name, $row) ? $row[$name] : null;
            $is_null = $row !== null && array_key_exists($name, $row) && $value === null;
            $id = 'db-field-' . $i;
        ?>
        <div class="form-group">
            <label for="<?= $id ?>" class="form-label db-field-label">
                <span class="ov-mono"><?= $e($name) ?></span>
                <span class="db-type"><?= $e($column['column_type']) ?><?= $column['key'] ? ', key' : '' ?></span>
            </label>
            <?php if (!$column['editable']): ?>
                <p class="form-hint" id="<?= $id ?>">
                    <?php if ($is_new): ?>
                        Filled in by the database.
                    <?php elseif ($value === null): ?>
                        NULL
                    <?php else: ?>
                        <?= App_database::is_text((string) $value) ? $e($value) : strlen((string) $value) . ' bytes' ?>
                        <?php if (App_database::is_binary($column)): ?>(change it with SQL)<?php endif; ?>
                    <?php endif; ?>
                </p>
            <?php elseif ($column['long'] || ($value !== null && (str_contains((string) $value, "\n") || strlen((string) $value) > 120))): ?>
                <textarea name="fields[<?= $e($name) ?>]" id="<?= $id ?>" class="form-input mono" rows="5">
<?= $e($value) ?></textarea>
            <?php else: ?>
                <input type="text" name="fields[<?= $e($name) ?>]" id="<?= $id ?>" class="form-input mono" value="<?= $e($value) ?>"<?= $is_new && $column['auto_increment'] ? ' placeholder="automatic"' : ($is_new && $column['default'] !== null ? ' placeholder="default: ' . $e($column['default']) . '"' : '') ?>>
            <?php endif; ?>
            <?php if ($column['editable'] && $column['nullable']): ?>
                <label class="checkbox-label db-null-toggle">
                    <input type="checkbox" name="null[<?= $e($name) ?>]" value="1"<?= $is_null ? ' checked' : '' ?>>
                    NULL when empty
                </label>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($is_new): ?>
            <p class="form-hint">Leave a field empty to use its default.</p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn-primary"><?= $is_new ? 'Add row' : 'Save row' ?></button>
            <a href="<?= $e($back_url) ?>" class="btn-secondary">Cancel</a>
        </div>
        <?= form_close() ?>
    </div>

    <?php if (!$is_new): ?>
    <div class="card danger-card">
        <?= form_open($base . '/row/delete', ['onsubmit' => "return confirm('Delete this row? This cannot be undone.')"]) ?>
            <input type="hidden" name="table" value="<?= $e($table) ?>">
            <?php foreach ($key as $name => $value): ?>
                <input type="hidden" name="key[<?= $e($name) ?>]" value="<?= $e($value) ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn-danger">Delete row</button>
        <?= form_close() ?>
    </div>
    <?php endif; ?>
</div>
