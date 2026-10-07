<?php foreach ($lines as $line): ?>
<div class="app-log-line" data-time="<?= htmlspecialchars($line['time']) ?>" data-pod="<?= htmlspecialchars($line['pod']) ?>"<?= $line['level'] !== '' ? ' data-level="' . htmlspecialchars($line['level']) . '"' : '' ?>><span class="app-log-time" title="<?= htmlspecialchars($line['time'] . ' · ' . $line['pod']) ?>"><?= htmlspecialchars(substr($line['time'], 11, 8)) ?></span><span class="app-log-level"><?= htmlspecialchars($line['level']) ?></span><span class="app-log-text"><?= htmlspecialchars($line['text']) ?></span></div>
<?php endforeach; ?>
