<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your first app - trongate.cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>apps_module/css/wizard.css">
</head>

<body class="wz-body">
    <?php $q_current = 2; $q_total = 6; require APPPATH . 'modules/apps/views/_wizard_top.php'; ?>

    <?php
    $form_action = 'onboarding/submit_app';
    $branches_url = 'onboarding/branches';
    $env_url = 'onboarding/env-suggestions';
    $q_offset = 1;
    require APPPATH . 'modules/apps/views/_app_steps.php';
    ?>
</body>

</html>
