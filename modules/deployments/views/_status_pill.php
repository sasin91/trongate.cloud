<span class="status-pill status-<?= htmlspecialchars($deployment->status) ?>" id="deployment-status">
    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $deployment->status))) ?>
    <?php if (!$is_finished): ?>
        <i class="log-poller-keepalive" hidden></i>
    <?php endif; ?>
</span>
