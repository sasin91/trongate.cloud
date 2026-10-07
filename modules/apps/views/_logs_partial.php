<?php
/**
 * Polled by #app-log-poller (see logs.php). Each block is picked up by an mx-select-oob entry:
 *   #new-log-lines  appended to #app-log-lines
 *   #log-cursor     written into the poller's value (the next ?since=)
 *   #log-note       replaces the note above the log (asleep, starting, ...)
 */
$lines = $logs['lines'] ?? [];
?>
<div id="new-log-lines"><?php require __DIR__ . '/_app_log_lines.php'; ?></div>
<div id="log-cursor"><?= htmlspecialchars($logs['cursor'] ?? $cursor) ?></div>
<?php require __DIR__ . '/_logs_note.php'; ?>
