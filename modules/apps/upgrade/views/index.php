<?php
/**
 * apps/{id}/upgrade (Upgrade::index). Expects: $app, $urls, $live_state, $can_rollback,
 * $upgrade (App_upgrades::latest_upgrade), $checking (a check is queued or running).
 */
?>
<div class="ov">
    <?php $active_tab = 'upgrade'; require APPPATH . 'modules/apps/views/_app_header.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>

    <div class="card settings-card">
        <h2>Trongate version</h2>

        <?php if ($app->type !== 'trongate_v2'): ?>
        <p class="form-hint">Upgrades are for Trongate v2 apps. Moving from v1 to v2 changes your own code, so it is not covered.</p>
        <?php elseif (!empty($app->source_public)): ?>
        <p class="form-hint">This app deploys from a public link, so we cannot open a pull request on its repository. Upgrades need the trongate.cloud GitHub app installed on a repository you own.</p>
        <?php else: ?>
        <p class="form-hint">Opens a pull request on your repository that moves your app to the newest Trongate release. Framework files you changed are merged with the newer ones, and your config values stay as they are. Nothing deploys until you merge it.</p>

        <?php require __DIR__ . '/_status.php'; ?>

        <?php if ($checking): ?>
        <!-- Polls the check until it finishes; removed by _status.php's #upgrade-poller-stop -->
        <input type="hidden" id="upgrade-poller"
               mx-get="apps/<?= (int) $app->id ?>/upgrade/status"
               mx-trigger="every 3s"
               mx-target="none"
               mx-select-oob='[
                   {"select": "#upgrade-status", "target": "#upgrade-status", "swap": "outerHTML"},
                   {"select": "#upgrade-poller-stop", "target": "#upgrade-poller", "swap": "delete"}
               ]'>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
