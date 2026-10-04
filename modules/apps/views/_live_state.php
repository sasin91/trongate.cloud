<?php if ($live_state !== null): ?>
    <span class="status-pill live-<?= htmlspecialchars($live_state['state']) ?>"><?= htmlspecialchars($live_state['label']) ?></span>
    <?php if ($live_state['detail'] !== ''): ?>
        <span class="muted"><?= htmlspecialchars($live_state['detail']) ?></span>
    <?php endif; ?>
<?php endif; ?>
