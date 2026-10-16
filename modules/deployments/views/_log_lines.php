<?php foreach ($logs as $log): ?>
<div class="log-line" data-log-id="<?= $log->id ?>"><span class="log-time"><?= date('H:i:s', strtotime($log->created_at)) ?></span><span class="log-level log-level-<?= htmlspecialchars($log->level) ?>"><?= htmlspecialchars($log->level) ?></span><span><?= htmlspecialchars($log->message) ?></span></div>
<?php endforeach; ?>
