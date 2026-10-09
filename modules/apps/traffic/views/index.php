<?php
/**
 * apps/{id}/traffic (Traffic::index). Expects: $app, $urls, $live_state, $can_rollback.
 */
?>
<div class="ov">
    <?php $active_tab = 'traffic'; require APPPATH . 'modules/apps/views/_app_header.php'; ?>

    <?php
    $traffic_poll_url = 'apps/' . (int) $app->id . '/traffic/poll';
    $traffic_file = $app->slug . '-traffic';
    $traffic_label = $app->label;
    $traffic_apps = null;
    require APPPATH . 'modules/apps/views/_traffic_live.php';
    ?>
</div>
