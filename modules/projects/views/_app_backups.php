<?php
/**
 * Deleted apps of the project with a backup of their database and files.
 *
 * Expects: $app_backups (rows from Projects::_app_backup_rows()), $backup_keep_days.
 */
?>
<section class="ov-card app-backups" aria-labelledby="app-backups-h">
    <div class="ov-card-head">
        <h2 id="app-backups-h">Deleted apps</h2>
        <span class="muted">backups kept for <?= (int) $backup_keep_days ?> days</span>
    </div>
    <ul class="app-backup-list">
        <?php foreach ($app_backups as $backup): ?>
            <li>
                <div class="app-backup-head">
                    <strong><?= htmlspecialchars($backup['name']) ?></strong>
                    <span class="status-pill <?= htmlspecialchars($backup['pill_class']) ?>"><?= htmlspecialchars($backup['status']) ?></span>
                </div>
                <p class="muted"><?= htmlspecialchars($backup['detail']) ?></p>
                <?php if ($backup['downloads'] !== []): ?>
                    <div class="app-backup-downloads">
                        <?php foreach ($backup['downloads'] as $label => $href): ?>
                            <a href="<?= htmlspecialchars($href) ?>" class="btn-secondary"><?= htmlspecialchars($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
