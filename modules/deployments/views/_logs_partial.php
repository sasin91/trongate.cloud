<?php
/**
 * Polled by #log-poller on the page (Deployments::_poll_swaps) and by
 * #log-modal-poller in the Logs modal (Deployments::_log_poll_swaps); each
 * picks the blocks it needs:
 *   #new-logs          appended to the modal's #logs-content
 *   #step-head-{key}   replaces that step's heading (its state)
 *   #step-now          replaces the step named next to "Steps"
 *   #log-cursor        written into the poller's value (next last_log_id)
 *   #deployment-status replaces the status pill
 *   #deploy-failure    replaces the "Deploy failed" card (empty until it fails)
 *   #log-modal-keepalive replaces the modal's keepalive (empty once finished)
 *   #log-poller-stop   deletes the first of ".log-poller-keepalive, #log-poller"
 *                      (".log-modal-running, #log-modal-poller" in the modal);
 *                      the pill only carries a keepalive while the deployment runs,
 *                      so once finished the poller itself is removed and polling stops.
 */
?>
<div id="new-logs"><?php require __DIR__ . '/_log_lines.php'; ?></div>
<?php foreach ($steps as $step): ?>
    <?php require __DIR__ . '/_step_head.php'; ?>
<?php endforeach; ?>
<?php require __DIR__ . '/_step_now.php'; ?>
<span id="log-modal-keepalive"><?php if (!$is_finished): ?><i class="log-modal-running" hidden></i><?php endif; ?></span>
<div id="log-cursor"><?= $last_log_id ?></div>
<?php require __DIR__ . '/_status_pill.php'; ?>
<?php require __DIR__ . '/_failure.php'; ?>
<div id="log-poller-stop"></div>
