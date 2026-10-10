<?php
/**
 * The app's processes as nodes in the overview's environment map, with what
 * each is doing now. Rendered inside _overview.php and polled from it
 * (Apps::processes_partial).
 *
 * Expects: $app, $processes (App_records::app_processes), $process_states
 * (Live_app_state::_live_process_states: array|null) and optionally $preview.
 */
$preview = $preview ?? false;
$e ??= fn($value) => htmlspecialchars((string) $value);

// Processes taken off the list keep running until the next deploy removes them
$removed = $process_states === null ? [] : array_diff_key($process_states, array_flip(array_column($processes, 'name')));
$nodes = $processes;
foreach (array_keys($removed) as $name) {
    $nodes[] = ['name' => (string) $name, 'command' => '', 'replicas' => 0];
}
?>
<?php foreach ($nodes as $process): ?>
    <?php
    $is_removed = isset($removed[$process['name']]);
    $state = $process_states[$process['name']] ?? null;
    if ($is_removed) {
        $status = ['class' => 'muted', 'label' => 'Removed on next deploy', 'detail' => ''];
    } elseif ($state !== null) {
        $status = ['class' => in_array($state['state'], ['crashing', 'stuck'], true) ? 'ov-warn-text' : '', 'label' => $state['label'], 'detail' => $state['detail']];
    } elseif ($process_states !== null) {
        $status = ['class' => 'muted', 'label' => 'Starts on next deploy', 'detail' => ''];
    } else {
        $status = null;
    }
    ?>
    <div class="ov-node ov-node-db ov-node-process">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 17l6-6-6-6"/><path d="M12 19h8"/></svg>
        <div>
            <span class="ov-node-kind">Process</span>
            <strong class="ov-mono"><?= $e($process['name']) ?><?= $process['replicas'] > 1 ? ' &times;' . (int) $process['replicas'] : '' ?></strong>
            <?php if ($process['command'] !== ''): ?>
                <code class="ov-process-command" title="<?= $e($process['command']) ?>"><?= $e($process['command']) ?></code>
            <?php endif; ?>
            <span class="muted">
                <?php if ($status !== null): ?>
                    <span class="<?= $status['class'] ?>"><?= $e($status['label']) ?></span><?= $status['detail'] !== '' ? ' &middot; ' . $e($status['detail']) : '' ?>
                <?php endif; ?>
                <?php if (!$preview): ?>
                    <?= $status !== null ? '&middot; ' : '' ?><a href="apps/<?= (int) $app->id ?>/logs">Logs</a> &middot; <a href="apps/<?= (int) $app->id ?>/settings#processes">Edit</a>
                <?php endif; ?>
            </span>
        </div>
    </div>
<?php endforeach; ?>
