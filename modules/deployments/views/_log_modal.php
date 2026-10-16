<?php
/**
 * The deployment's log, in the MX modal the Steps card's Logs button opens.
 * While the deployment runs, #log-modal-poller (started by deploy_log.js)
 * fetches new lines from _logs_partial.php; once it has finished,
 * #log-modal-keepalive comes back empty and the poller deletes itself.
 */
?>
<div class="log-container log-modal-box">
    <div id="logs-content"><?php require __DIR__ . '/_log_lines.php'; ?></div>
</div>
<?php if (!$is_finished): ?>
    <span id="log-modal-keepalive"><i class="log-modal-running" hidden></i></span>
    <input type="hidden" id="log-modal-poller" value="<?= $last_log_id ?>"
           mx-get="deployments/<?= $deployment->id ?>/logs?last_log_id=${this.value}"
           mx-target="none"
           mx-select-oob='<?= htmlspecialchars($poll_swaps, ENT_QUOTES) ?>'>
<?php endif; ?>
