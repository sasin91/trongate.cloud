<div class="wz-embedded">
    <?php $q_current = 1; $q_total = 8; require __DIR__ . '/_wizard_top.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>

    <?php
    extract($form);
    $form_action = 'projects/' . (int) $project->id . '/apps/submit';
    $draft_url = 'projects/' . (int) $project->id . '/apps/draft';
    $branches_url = 'apps/branches';
    $env_url = 'apps/env-suggestions';
    $sql_url = 'apps/sql-files';
    $build_url = 'apps/build-suggestions';
    $q_offset = 0;
    $cancel_action = 'projects/' . (int) $project->id . '/apps/cancel';
    require __DIR__ . '/_app_steps.php';
    ?>
</div>
