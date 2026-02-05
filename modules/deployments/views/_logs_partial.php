<?php foreach ($logs as $log): ?>
<div class="log-line" data-log-id="<?= $log->id ?>" style="margin-bottom: 2px;">
    <span style="color: #666; margin-right: 8px;"><?= date('H:i:s', strtotime($log->created_at)) ?></span>
    <span style="color: <?= $log->level === 'error' ? '#f85149' : ($log->level === 'warn' ? '#d29922' : '#58a6ff') ?>; margin-right: 8px; text-transform: uppercase; font-size: 0.75rem;"><?= htmlspecialchars($log->level) ?></span>
    <span><?= htmlspecialchars($log->message) ?></span>
</div>
<?php endforeach; ?>
