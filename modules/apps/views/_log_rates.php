<?php
/**
 * Errors card on the app overview: errors and warnings per hour over the last
 * 24 hours, sampled from the app's log (App_log_rates). Expects: $app, $log_rates.
 */
$errors = array_sum(array_column($log_rates, 'errors'));
$warnings = array_sum(array_column($log_rates, 'warnings'));
$sampled = array_sum(array_column($log_rates, 'total'));
$peak = max(1, ...array_map(fn(array $hour) => $hour['errors'] + $hour['warnings'], $log_rates));
$plural = fn(int $count, string $word) => number_format($count) . ' ' . $word . ($count === 1 ? '' : 's');
?>
<section class="ov-card" aria-labelledby="err-h">
    <div class="ov-card-head">
        <h2 id="err-h">Errors</h2>
        <a href="apps/<?= (int) $app->id ?>/logs">View logs</a>
    </div>
    <div class="ov-rates">
        <?php if ($sampled === 0): ?>
            <p class="muted">Nothing logged in the last 24 hours. Counts start once your app writes output while it is awake.</p>
        <?php else: ?>
            <p class="ov-rates-sum">
                <span><i class="ov-swatch ov-swatch-error" aria-hidden="true"></i><strong><?= $plural($errors, 'error') ?></strong></span>
                <span><i class="ov-swatch ov-swatch-warn" aria-hidden="true"></i><strong><?= $plural($warnings, 'warning') ?></strong></span>
                <span class="muted">last 24 h</span>
            </p>
            <div class="ov-rates-bars" role="img" aria-label="<?= htmlspecialchars($plural($errors, 'error') . ' and ' . $plural($warnings, 'warning') . ' in the last 24 hours, per hour') ?>">
                <?php foreach ($log_rates as $hour): ?>
                    <span class="ov-rate-bar" title="<?= htmlspecialchars(substr($hour['hour'], 11, 5) . ' UTC: ' . $plural($hour['errors'], 'error') . ', ' . $plural($hour['warnings'], 'warning') . ' of ' . $plural($hour['total'], 'line')) ?>">
                        <?php if ($hour['warnings'] > 0): ?><i class="ov-rate-warn" style="height: <?= round($hour['warnings'] / $peak * 100, 1) ?>%"></i><?php endif; ?>
                        <?php if ($hour['errors'] > 0): ?><i class="ov-rate-error" style="height: <?= round($hour['errors'] / $peak * 100, 1) ?>%"></i><?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <div class="ov-rates-axis muted" aria-hidden="true"><span>24 h ago</span><span>now</span></div>
        <?php endif; ?>
    </div>
</section>
