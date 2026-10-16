<?php
/**
 * Polled by #log-poller (see show.php; Deployments::_poll_swaps lists the swaps):
 *   #new-logs          appended to #logs-content
 *   #step-head-{key}   replaces that step's heading (its state)
 *   #step-now          replaces the step named next to "Steps"
 *   #log-cursor        written into the poller's value (next last_log_id)
 *   #deployment-status replaces the status pill
 *   #deploy-failure    replaces the "Deploy failed" card (empty until it fails)
 *   #log-poller-stop   deletes the first of ".log-poller-keepalive, #log-poller";
 *                      the pill only carries a keepalive while the deployment runs,
 *                      so once finished the poller itself is removed and polling stops.
 */
?>
<div id="new-logs"><?php require __DIR__ . '/_log_lines.php'; ?></div>
<?php foreach ($steps as $step): ?>
    <?php require __DIR__ . '/_step_head.php'; ?>
<?php endforeach; ?>
<?php require __DIR__ . '/_step_now.php'; ?>
<div id="log-cursor"><?= $last_log_id ?></div>
<?php require __DIR__ . '/_status_pill.php'; ?>
<?php require __DIR__ . '/_failure.php'; ?>
<div id="log-poller-stop"></div>
