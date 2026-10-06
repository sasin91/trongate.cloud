<?php foreach ($lines as $line): ?>
<div class="app-log-line"><span class="app-log-time" title="<?= htmlspecialchars($line['time'] . ' · ' . $line['pod']) ?>"><?= htmlspecialchars(substr($line['time'], 11, 8)) ?></span><span><?= htmlspecialchars($line['text']) ?></span></div>
<?php endforeach; ?>
