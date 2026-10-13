<?php
/**
 * The Import view's panel (views/_import.php), also answered on its own by
 * import/status for the poller: the latest import with its progress and
 * buttons, then the upload form when another file may be imported.
 *
 * Expects (App_database_import::_import_data): $import (null, or heading,
 * lines, warning, error, percent, failed, actions), $import_base,
 * $import_can_upload, $import_replaces, $import_polling, $import_start_url,
 * $import_chunk_url, $import_finish_url, $import_chunk_bytes, $import_max_label.
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div id="db-import-status">
    <?php if (!$import_polling): ?><span id="db-import-poller-stop" hidden></span><?php endif; ?>

    <?php if ($import !== null): ?>
    <section class="db-import-card<?= $import['failed'] ? ' db-import-failed' : '' ?>">
        <h2><?= $e($import['heading']) ?></h2>
        <?php if ($import['percent'] !== null): ?>
            <div class="db-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $import['percent'] ?>"><span style="width: <?= (int) $import['percent'] ?>%"></span></div>
        <?php elseif ($import_polling): ?>
            <div class="db-progress db-progress-busy" role="progressbar"><span></span></div>
        <?php endif; ?>
        <?php if ($import['error'] !== null): ?>
            <p class="db-error" role="alert"><?= $e($import['error']) ?></p>
        <?php endif; ?>
        <?php foreach ($import['lines'] as $line): ?>
            <p><?= $e($line) ?></p>
        <?php endforeach; ?>
        <?php if ($import['warning'] !== null): ?>
            <p class="db-import-warning"><?= $e($import['warning']) ?></p>
        <?php endif; ?>
        <?php if ($import['actions'] !== []): ?>
        <div class="db-import-actions">
            <?php foreach ($import['actions'] as $action): ?>
            <?= form_open($import_base . '/' . $action['path']) ?>
                <input type="hidden" name="import" value="<?= (int) $import['id'] ?>">
                <?php if ($action['skip']): ?><input type="hidden" name="skip" value="1"><?php endif; ?>
                <button type="submit" class="<?= $action['primary'] ? 'btn-primary' : 'btn-secondary' ?>"><?= $e($action['label']) ?></button>
            <?= form_close() ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($import_can_upload): ?>
    <?= form_open_upload($import_start_url, [
        'class' => 'db-import-card db-import-form',
        'data-sql-import' => '',
        'data-chunk-url' => $import_chunk_url,
        'data-finish-url' => $import_finish_url,
        'data-chunk-bytes' => (string) $import_chunk_bytes,
    ]) ?>
        <h2>Import a .sql file</h2>
        <p>A database dump (mysqldump, mariadb-dump, phpMyAdmin, TablePlus) or any SQL, plain or gzipped, up to <?= $e($import_max_label) ?>. It is checked first, and nothing runs until you say so.</p>
        <?php if ($import_replaces !== null): ?>
            <p class="muted">Importing another file discards <?= $e($import_replaces) ?>.</p>
        <?php endif; ?>
        <p class="db-error" role="alert" data-import-error hidden></p>
        <input type="file" name="file" accept=".sql,.gz,application/sql,application/gzip" required>
        <div class="db-progress" data-import-progress hidden><span></span></div>
        <p class="muted" data-import-progress-text hidden></p>
        <div class="db-import-actions">
            <button type="submit" class="btn-primary">Upload and check</button>
        </div>
    <?= form_close() ?>
    <?php endif; ?>
</div>
