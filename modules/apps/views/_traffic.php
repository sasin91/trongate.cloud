<?php
/**
 * Traffic card: requests per hour over the last 24 hours by outcome, with
 * totals, server error rate, response times and data sent (App_traffic).
 * Used on the app overview and, with a per-app table, on the project page.
 *
 * Expects: $traffic (App_traffic::report() or false when it could not be
 * read), and optionally $traffic_apps (app rows by id: adds the per-app
 * table) and $traffic_link (href for the card's head link).
 */
$traffic_apps = $traffic_apps ?? null;
$plural = fn(int $count, string $word) => number_format($count) . ' ' . $word . ($count === 1 ? '' : 's');
// Two decimals below 0.1% so a rare error does not read as 0%
$percent = fn(int $part, int $whole) => $whole === 0 ? '0%' : round($part / $whole * 100, $part * 1000 < $whole ? 2 : 1) . '%';
$duration = fn(?float $seconds) => match (true) {
    $seconds === null => 'n/a',
    is_infinite($seconds) => 'over 10 s',
    $seconds < 1 => round($seconds * 1000) . ' ms',
    default => number_format($seconds, 1) . ' s',
};
$size = function (int $bytes): string {
    foreach (['GB' => 1e9, 'MB' => 1e6, 'KB' => 1e3] as $unit => $factor) {
        if ($bytes >= $factor) {
            return number_format($bytes / $factor, $bytes >= $factor * 10 ? 0 : 1) . ' ' . $unit;
        }
    }

    return $bytes . ' B';
};
?>
<section class="ov-card" aria-labelledby="traffic-h">
    <div class="ov-card-head">
        <h2 id="traffic-h">Traffic</h2>
        <span class="muted">last 24 h</span>
    </div>
    <div class="ov-rates">
        <?php if ($traffic === false): ?>
            <p class="muted">Traffic numbers are unavailable right now. Your apps are not affected.</p>
        <?php elseif ($traffic['requests'] === 0): ?>
            <p class="muted">No requests in the last 24 hours.</p>
        <?php else: ?>
            <?php $peak = max(1, ...array_map(fn(array $hour) => $hour['ok'] + $hour['client_errors'] + $hour['server_errors'], $traffic['hours'])); ?>
            <dl class="ov-traffic-stats">
                <div><dt>Requests</dt><dd><?= number_format($traffic['requests']) ?></dd></div>
                <div><dt>Server errors</dt><dd<?= $traffic['server_errors'] > 0 ? ' class="ov-traffic-bad"' : '' ?>><?= $percent($traffic['server_errors'], $traffic['requests']) ?></dd></div>
                <?php if ($traffic_apps === null): ?>
                    <div><dt>Median</dt><dd><?= $duration($traffic['median']) ?></dd></div>
                    <div><dt>p95</dt><dd><?= $duration($traffic['p95']) ?></dd></div>
                <?php endif; ?>
                <div><dt>Sent</dt><dd><?= $size($traffic['bytes_out']) ?></dd></div>
            </dl>
            <p class="ov-rates-sum">
                <span><i class="ov-swatch ov-swatch-ok" aria-hidden="true"></i><?= number_format($traffic['requests'] - $traffic['client_errors'] - $traffic['server_errors']) ?> answered</span>
                <span><i class="ov-swatch ov-swatch-warn" aria-hidden="true"></i><?= number_format($traffic['client_errors']) ?> 4xx</span>
                <span><i class="ov-swatch ov-swatch-error" aria-hidden="true"></i><?= number_format($traffic['server_errors']) ?> 5xx</span>
            </p>
            <div class="ov-rates-bars" role="img" aria-label="<?= htmlspecialchars($plural($traffic['requests'], 'request') . ' in the last 24 hours, per hour') ?>">
                <?php foreach ($traffic['hours'] as $hour): ?>
                    <span class="ov-rate-bar" title="<?= htmlspecialchars(gmdate('H:i', $hour['start']) . ' UTC: ' . $plural($hour['ok'] + $hour['client_errors'] + $hour['server_errors'], 'request') . ', ' . number_format($hour['client_errors']) . ' 4xx, ' . number_format($hour['server_errors']) . ' 5xx') ?>">
                        <?php if ($hour['server_errors'] > 0): ?><i class="ov-rate-error" style="height: <?= round($hour['server_errors'] / $peak * 100, 1) ?>%"></i><?php endif; ?>
                        <?php if ($hour['client_errors'] > 0): ?><i class="ov-rate-warn" style="height: <?= round($hour['client_errors'] / $peak * 100, 1) ?>%"></i><?php endif; ?>
                        <?php if ($hour['ok'] > 0): ?><i class="ov-rate-ok" style="height: <?= round($hour['ok'] / $peak * 100, 1) ?>%"></i><?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <div class="ov-rates-axis muted" aria-hidden="true"><span>24 h ago</span><span>now</span></div>
        <?php endif; ?>
    </div>
    <?php if ($traffic !== false && $traffic_apps !== null && ($traffic['apps'] ?? []) !== []): ?>
        <div class="ov-table-wrap">
            <table class="deployments-table ov-traffic-apps">
                <thead>
                    <tr>
                        <th>App</th>
                        <th>Requests</th>
                        <th>Server errors</th>
                        <th>Median</th>
                        <th>p95</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($traffic['apps'] as $app_id => $row): ?>
                        <?php if (!isset($traffic_apps[$app_id])) continue; ?>
                        <tr>
                            <td><a href="apps/<?= (int) $app_id ?>"><?= htmlspecialchars((string) $traffic_apps[$app_id]->label) ?></a></td>
                            <td><?= number_format($row['requests']) ?></td>
                            <td<?= $row['server_errors'] > 0 ? ' class="ov-traffic-bad"' : '' ?>><?= $percent($row['server_errors'], $row['requests']) ?></td>
                            <td class="muted"><?= $duration($row['median']) ?></td>
                            <td class="muted"><?= $duration($row['p95']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
