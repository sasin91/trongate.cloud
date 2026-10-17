<?php
/**
 * One step's heading: its number (a check once done), title, state and the
 * time the deployment entered it. Swapped by the log poller as $step changes.
 */
?>
<section id="step-head-<?= $step['key'] ?>" class="step-head step-<?= htmlspecialchars($step['state']) ?>">
    <span class="step-dot"><?= $step['number'] ?></span>
    <h4><?= htmlspecialchars($step['title']) ?></h4>
    <span class="step-label"><?= htmlspecialchars($step['label']) ?></span>
    <span class="step-time"><?= htmlspecialchars($step['time']) ?></span>
</section>
