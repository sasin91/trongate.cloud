<div class="wz-embedded">
    <?php $q_current = 1; $q_total = 7; require __DIR__ . '/_wizard_top.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>

    <?php
    extract($form);
    $form_action = 'projects/' . (int) $project->id . '/apps/submit';
    $branches_url = 'apps/branches';
    $env_url = 'apps/env-suggestions';
    $sql_url = 'apps/sql-files';
    $q_offset = 0;
    $back_href = 'projects/' . (int) $project->id;
    require __DIR__ . '/_app_steps.php';
    ?>
</div>
