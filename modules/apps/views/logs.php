<?php
/**
 * apps/{id}/logs. Expects: $app, $urls, $live_state, $can_rollback,
 * $logs (App_logs::read result, or null when unreadable or never deployed).
 */
?>
<div class="ov">
    <?php $active_tab = 'logs'; require __DIR__ . '/_app_header.php'; ?>

    <section class="ov-card app-logs" aria-labelledby="logs-h">
        <div class="ov-card-head">
            <h2 id="logs-h">Output</h2>
            <span class="muted">Last <?= App_logs::TAIL_LINES ?> lines &middot; times in UTC</span>
        </div>
        <?php require __DIR__ . '/_logs_note.php'; ?>
        <div class="app-log-box" id="app-log-box" tabindex="0" aria-label="App output" aria-live="polite">
            <div id="app-log-lines"><?php $lines = $logs['lines'] ?? []; require __DIR__ . '/_app_log_lines.php'; ?></div>
        </div>
    </section>

    <?php if (!empty($app->current_deployment_id)): ?>
        <!-- Polls for new lines; see _logs_partial.php -->
        <input type="hidden" id="app-log-poller" value="<?= htmlspecialchars($logs['cursor'] ?? '') ?>"
               mx-get="apps/<?= (int) $app->id ?>/logs/poll?since=${this.value}"
               mx-trigger="every 3s"
               mx-target="none"
               mx-select-oob='[
                   {"select": "#new-log-lines", "target": "#app-log-lines", "swap": "beforeend"},
                   {"select": "#log-cursor", "target": "#app-log-poller", "swap": "value"},
                   {"select": "#log-note", "target": "#log-note", "swap": "outerHTML"}
               ]'>
    <?php endif; ?>
</div>
