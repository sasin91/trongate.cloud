<div class="content">
    <div style="margin-bottom: 1.5rem;">
        <a href="apps/<?= $deployment->app_id ?>" style="color: var(--accent); text-decoration: none;">&larr; Back to <?= htmlspecialchars($deployment->app_label) ?></a>
    </div>

    <div class="card" style="margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0;">Deployment #<?= $deployment->id ?></h2>
            <?php require __DIR__ . '/_status_pill.php'; ?>
        </div>
        <dl class="deployment-meta">
            <dt>Source</dt>
            <dd><?= htmlspecialchars((string) $deployment->source_url) ?></dd>
            <dt>Trigger</dt>
            <dd><?= htmlspecialchars($deployment->trigger_type) ?></dd>
            <?php if (!empty($deployment->commit_sha)): ?>
                <dt>Commit</dt>
                <dd><code><?= htmlspecialchars($deployment->commit_sha) ?></code></dd>
            <?php endif; ?>
            <?php if (!empty($deployment->image)): ?>
                <dt>Image</dt>
                <dd><code><?= htmlspecialchars($deployment->image) ?></code></dd>
            <?php endif; ?>
            <dt>Started</dt>
            <dd><?= htmlspecialchars($deployment->created_at) ?></dd>
            <?php if (!empty($deployment->completed_at)): ?>
                <dt>Finished</dt>
                <dd><?= htmlspecialchars($deployment->completed_at) ?></dd>
            <?php endif; ?>
        </dl>
    </div>

    <h3>Logs</h3>
    <div id="log-container" class="card log-container">
        <div id="logs-content"><?php require __DIR__ . '/_log_lines.php'; ?></div>
    </div>

    <?php if (!$is_finished): ?>
        <!-- Polls for new log lines; removed by _logs_partial.php once the deployment finishes -->
        <input type="hidden" id="log-poller" value="<?= $last_log_id ?>"
               mx-get="deployments/<?= $deployment->id ?>/logs?last_log_id=${this.value}"
               mx-trigger="every 2s"
               mx-target="none"
               mx-select-oob='[
                   {"select": "#new-logs", "target": "#logs-content", "swap": "beforeend"},
                   {"select": "#log-cursor", "target": "#log-poller", "swap": "value"},
                   {"select": "#deployment-status", "target": "#deployment-status", "swap": "outerHTML"},
                   {"select": "#log-poller-stop", "target": ".log-poller-keepalive, #log-poller", "swap": "delete"}
               ]'>
    <?php endif; ?>

    <?php if ($deployment->status === 'success' && !in_array($deployment->trigger_type, ['webhook', 'reconcile'], true)): ?>
        <div class="card" style="margin-top: 1.5rem; background: #f0f9ff; border: 1px solid #0284c7;">
            <h4 style="margin-top: 0; color: #0284c7;">Deploy on every push</h4>
            <p>Pushes to your branch now deploy automatically through the trongate.cloud GitHub app. You can turn that off in the app's settings.</p>
            <a href="apps/<?= $deployment->app_id ?>/settings" class="btn-create">App settings</a>
        </div>
    <?php endif; ?>
</div>
