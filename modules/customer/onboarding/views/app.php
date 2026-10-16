<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your first app - trongate.cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>apps_module/css/wizard.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>sql_check_module/css/sql_check.css">
</head>

<body class="wz-body">
    <?php $q_current = 2; $q_total = 9; require APPPATH . 'modules/apps/views/_wizard_top.php'; ?>

    <?php
    $form_action = 'onboarding/submit_app';
    $draft_url = 'onboarding/draft';
    $branches_url = 'onboarding/branches';
    $env_url = 'onboarding/env-suggestions';
    $sql_url = 'onboarding/sql-files';
    $build_url = 'onboarding/build-suggestions';
    $q_offset = 1;
    require APPPATH . 'modules/apps/views/_app_steps.php';
    ?>
    <script src="<?= BASE_URL ?>js/trongate-mx.min.js"></script>
</body>

</html>
