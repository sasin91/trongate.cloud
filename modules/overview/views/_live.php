<?php
/**
 * The live part of the overview, rendered on the page and again every few
 * seconds (Overview::live). $cluster is null when the cluster can't be read.
 */
$size = function (?int $bytes): string {
    if ($bytes === null) {
        return '–';
    }
    return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1) . ' GiB' : round($bytes / 1024 ** 2) . ' MiB';
};
$cores = function (?int $millis, bool $unit = true): string {
    if ($millis === null) {
        return '–';
    }
    if ($millis < 1000) {
        return $millis . 'm';
    }
    $count = round($millis / 1000, 2);
    return $count . ($unit ? ($count == 1 ? ' core' : ' cores') : '');
};
$percent = fn(?int $part, int $whole): ?int => ($part === null || $whole <= 0) ? null : (int) round($part / $whole * 100);
$bar = function (string $label, ?int $part, int $whole, string $text) use ($percent): string {
    $pct = $percent($part, $whole);
    $level = $pct === null ? '' : ($pct >= 90 ? ' ovw-high' : ($pct >= 75 ? ' ovw-warn' : ''));
    $width = $pct === null ? 0 : min($pct, 100);
    return '<div class="ovw-bar' . $level . '"><div class="ovw-bar-label"><span>' . $label . '</span><span>' . out($text)
        . ($pct === null ? '' : ' · ' . $pct . '%') . '</span></div><div class="ovw-track"><div style="width: ' . $width . '%"></div></div></div>';
};
$state_badge = function (?array $state): string {
    if ($state === null) {
        return '<span class="ovw-state ovw-state-unknown">Unknown</span>';
    }
    return '<span class="ovw-state ovw-state-' . out($state['state']) . '" title="' . out($state['detail']) . '">' . out($state['label']) . '</span>';
};
?>
<?php if ($cluster === null): ?>
<p class="ovw-notice">The cluster can't be read from here, so live state and usage are missing.</p>
<?php else: ?>
<?php if (!$cluster['metrics']): ?>
<p class="ovw-notice">metrics-server didn't answer, so usage is missing; requests are still shown.</p>
<?php endif; ?>

<h2>Nodes</h2>
<div class="ovw-nodes">
    <?php foreach ($cluster['nodes'] as $node): ?>
    <div class="card ovw-node<?= (!$node['ready'] || $node['pressure']) ? ' ovw-node-bad' : '' ?>">
        <div class="card-heading">
            <span><?= out($node['name']) ?></span>
            <span class="ovw-node-tags">
                <?= out(trim($node['type'] . ' ' . $node['zone'])) ?>
                <?= $node['ready'] ? '<span class="ovw-state ovw-state-running">Ready</span>' : '<span class="ovw-state ovw-state-crashing">Not ready</span>' ?>
                <?php foreach ($node['pressure'] as $pressure): ?>
                <span class="ovw-state ovw-state-crashing"><?= out($pressure) ?></span>
                <?php endforeach; ?>
            </span>
        </div>
        <div class="card-body">
            <?= $bar('Memory used', $node['memory']['used'], $node['memory']['allocatable'], $size($node['memory']['used']) . ' of ' . $size($node['memory']['allocatable'])) ?>
            <?= $bar('Memory requested', $node['memory']['requested'], $node['memory']['allocatable'], $size($node['memory']['requested'])) ?>
            <?= $bar('CPU used', $node['cpu']['used'], $node['cpu']['allocatable'], $cores($node['cpu']['used'], false) . ' of ' . $cores($node['cpu']['allocatable'])) ?>
            <?= $bar('CPU requested', $node['cpu']['requested'], $node['cpu']['allocatable'], $cores($node['cpu']['requested'])) ?>
            <?= $bar('Pods', $node['pods']['running'], $node['pods']['capacity'], $node['pods']['running'] . ' of ' . $node['pods']['capacity']) ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<p class="ovw-hint">Requested is what the scheduler has promised the pods on a node; a new pod only fits where its request still fits. Used is what they use now.</p>

<?php if ($cluster['pending']): ?>
<h2 class="ovw-bad">Waiting for room (<?= count($cluster['pending']) ?>)</h2>
<div class="table-container">
    <div class="ovw-scroll"><table class="records-table">
        <thead><tr><th class="text-left">Pod</th><th class="text-left">Why</th></tr></thead>
        <tbody>
            <?php foreach ($cluster['pending'] as $pod): ?>
            <tr><td><?= out($pod['namespace'] . '/' . $pod['name']) ?></td><td class="ovw-reason"><?= out($pod['reason']) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
<?php endif; ?>

<h2>Apps</h2>
<?php if (!$apps): ?>
<p>No apps yet.</p>
<?php else: ?>
<div class="table-container">
    <div class="ovw-scroll"><table class="records-table">
        <thead>
            <tr>
                <th class="text-left">App</th>
                <th class="text-left">Project</th>
                <th class="text-left">State</th>
                <th class="text-left">Memory</th>
                <th class="text-left">CPU</th>
                <th class="text-left">Pods</th>
                <th class="text-left">Last deploy</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($apps as $app):
                $live = $cluster['apps'][(int) $app->id] ?? null; ?>
            <tr>
                <td><a href="app/show/<?= (int) $app->id ?>"><?= out($app->label) ?></a> <span class="ovw-muted"><?= out($app->slug) ?><?= $app->always_on ? ' · always on' : '' ?><?= $app->processes > 0 ? ' · ' . (int) $app->processes . ($app->processes == 1 ? ' process' : ' processes') : '' ?></span></td>
                <td><a href="project/show/<?= (int) $app->project_id ?>"><?= out($app->project_name) ?></a></td>
                <td><?= $cluster === null ? $state_badge(null) : $state_badge($live['state'] ?? App_state::_make('not_running')) ?></td>
                <td><?= $size($live['memory'] ?? null) ?></td>
                <td><?= $cores($live['cpu'] ?? null) ?></td>
                <td><?= (int) ($live['pods'] ?? 0) ?></td>
                <td><?= $app->deployment_status === null ? '<span class="ovw-muted">Never</span>' : out($app->deployment_status) . ' <span class="ovw-muted">' . out((string) $app->deployed_at) . '</span>' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($services): ?>
<h2>Services</h2>
<div class="table-container">
    <div class="ovw-scroll"><table class="records-table">
        <thead>
            <tr>
                <th class="text-left">Service</th>
                <th class="text-left">Project</th>
                <th class="text-left">State</th>
                <th class="text-left">Memory</th>
                <th class="text-left">CPU</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($services as $service):
                $live = $cluster['services'][(int) $service->id] ?? null; ?>
            <tr>
                <td><a href="service/show/<?= (int) $service->id ?>"><?= out($service->name) ?></a> <span class="ovw-muted"><?= out($service->image) ?></span></td>
                <td><a href="project/show/<?= (int) $service->project_id ?>"><?= out($service->project_name) ?></a></td>
                <td><?= $cluster === null ? $state_badge(null) : $state_badge($live['state'] ?? App_state::_make('not_running')) ?></td>
                <td><?= $size($live['memory'] ?? null) ?> <span class="ovw-muted">of <?= (int) $service->memory_mb ?> MiB</span></td>
                <td><?= $cores($live['cpu'] ?? null) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
