<?php
/**
 * Polled by #log-poller (see show.php). Each block is picked up by an mx-select-oob entry:
 *   #new-logs          appended to #logs-content
 *   #log-cursor        written into the poller's value (next last_log_id)
 *   #deployment-status replaces the status pill
 *   #log-poller-stop   deletes the first of ".log-poller-keepalive, #log-poller";
 *                      the pill only carries a keepalive while the deployment runs,
 *                      so once finished the poller itself is removed and polling stops.
 */
?>
<div id="new-logs"><?php require __DIR__ . '/_log_lines.php'; ?></div>
<div id="log-cursor"><?= $last_log_id ?></div>
<?php require __DIR__ . '/_status_pill.php'; ?>
<div id="log-poller-stop"></div>
