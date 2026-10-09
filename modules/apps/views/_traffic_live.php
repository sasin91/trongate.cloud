<?php
/**
 * Live traffic card, filled and kept up to date by app_traffic.js from
 * $traffic_poll_url (Live_app_state::_send_traffic). Shared by the app's
 * Traffic tab and the project's Traffic page.
 *
 * Expects: $traffic_poll_url, $traffic_file (CSV file name without extension),
 * $traffic_apps (app id => label on the project page, null on an app's) and,
 * on an app's, $traffic_label (its name for the CSV).
 */
$e = fn($value) => htmlspecialchars((string) $value);
$by_app = $traffic_apps !== null;
?>
<section class="ov-card traffic" id="traffic"
         data-poll-url="<?= $e($traffic_poll_url) ?>"
         data-file="<?= $e($traffic_file) ?>"
         data-label="<?= $e($traffic_label ?? '') ?>"
         <?php if ($by_app): ?>data-apps="<?= $e(json_encode($traffic_apps, JSON_FORCE_OBJECT)) ?>"<?php endif; ?>
         aria-labelledby="traffic-h">
    <div class="ov-card-head traffic-head">
        <h2 id="traffic-h">Live traffic</h2>
        <span class="muted" id="traffic-status" aria-live="polite">Connecting&hellip;</span>
        <span class="traffic-actions">
            <span class="traffic-recording muted" id="traffic-recording" hidden></span>
            <button type="button" class="btn-secondary" id="traffic-record">Start recording</button>
        </span>
    </div>

    <div class="ov-rates">
        <dl class="ov-traffic-stats">
            <div><dt>Requests</dt><dd data-stat="requests">0</dd></div>
            <div><dt>Last minute</dt><dd data-stat="per_minute">0</dd></div>
            <div><dt>Server errors</dt><dd data-stat="server_errors">0%</dd></div>
            <div><dt>Median</dt><dd data-stat="median">n/a</dd></div>
            <div><dt>p95</dt><dd data-stat="p95">n/a</dd></div>
            <div><dt>Sent</dt><dd data-stat="bytes">0 B</dd></div>
        </dl>
        <p class="ov-rates-sum">
            <span><i class="ov-swatch ov-swatch-ok" aria-hidden="true"></i><span data-stat="ok">0</span> answered</span>
            <span><i class="ov-swatch ov-swatch-warn" aria-hidden="true"></i><span data-stat="client_errors">0</span> 4xx</span>
            <span><i class="ov-swatch ov-swatch-error" aria-hidden="true"></i><span data-stat="server_error_count">0</span> 5xx</span>
        </p>
        <svg class="traffic-chart" id="traffic-chart" viewBox="0 0 600 160" preserveAspectRatio="none" role="img" aria-label="Requests over the last 5 minutes"></svg>
        <div class="ov-rates-axis muted" aria-hidden="true">
            <span id="traffic-chart-from">5 min ago</span>
            <span class="traffic-chart-legend"><i class="traffic-p95-key"></i><span>p95 time taken (right scale, <span id="traffic-chart-p95">0 ms</span> max)</span></span>
            <span id="traffic-chart-to">now</span>
        </div>
    </div>

    <?php if ($by_app): ?>
        <div class="ov-table-wrap">
            <table class="deployments-table traffic-table">
                <thead><tr><th>App</th><th>Requests</th><th>Server errors</th><th>Median</th><th>p95</th></tr></thead>
                <tbody id="traffic-apps"></tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="ov-card-head traffic-head">
        <h2>Requests</h2>
        <label class="checkbox-label app-log-filter">
            <input type="checkbox" id="traffic-errors-only">
            Errors only
        </label>
        <span class="muted">Newest first &middot; times in UTC</span>
    </div>
    <div class="ov-table-wrap traffic-list">
        <table class="deployments-table traffic-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <?php if ($by_app): ?><th>App</th><?php endif; ?>
                    <th>Request</th>
                    <th>Status</th>
                    <th>Time taken</th>
                    <th>Size</th>
                </tr>
            </thead>
            <tbody id="traffic-requests"></tbody>
        </table>
        <p class="ov-empty muted" id="traffic-empty">No requests in the last 5 minutes yet. New ones show up here as they arrive.</p>
    </div>
    <p class="traffic-note muted">Shows the last 5 minutes and every request while this page is open. Nothing is stored; record to download requests as CSV (up to 60 minutes or 200,000 requests).</p>
</section>
