<?php
/**
 * What the logs page is showing, or why there is nothing to show.
 * Expects: $app, $logs (App_logs::read result, or null when unreadable).
 */
$state = $logs['state']['state'] ?? null;
$note = match (true) {
    empty($app->current_deployment_id) => 'No logs yet. Your app\'s output shows up here once it is deployed.',
    $logs === null => 'The logs cannot be read right now. Trying again shortly.',
    $state === 'asleep' => 'Your app is asleep, so it has no output right now. It wakes on its next visit and new lines show up here. Opening this page does not wake it.',
    $state === 'crashing' => 'Your app keeps stopping. The lines below are the output of its last attempt to start.',
    $logs['pods'] === 0 => 'Your app is starting. Its output shows up here once it runs.',
    default => '',
};
?>
<p id="log-note" class="log-note<?= $state === null ? '' : ' log-note-' . htmlspecialchars($state) ?>"<?= $note === '' ? ' hidden' : '' ?>><?= htmlspecialchars($note) ?></p>
