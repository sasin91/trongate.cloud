<?php
/**
 * projects/{id}/traffic (Projects::traffic). Expects: $project, $app_count,
 * $member_count, $is_owner, $traffic_apps (app id => label).
 */
?>
<div class="ov">
    <?php $active_tab = 'traffic'; require __DIR__ . '/_project_header.php'; ?>

    <?php
    $traffic_poll_url = 'projects/' . (int) $project->id . '/traffic/poll';
    $traffic_file = 'project-' . (int) $project->id . '-traffic';
    require APPPATH . 'modules/apps/views/_traffic_live.php';
    ?>
</div>
