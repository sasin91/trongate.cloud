<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your first app - trongate.cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>projects_module/css/wizard.css">
</head>

<body class="wz-body">
    <?php $q_current = 2; $q_total = 5; require APPPATH . 'modules/projects/views/_wizard_top.php'; ?>

    <?php
    $form_action = 'onboarding/submit_project';
    $branches_url = 'onboarding/branches';
    $q_offset = 1;
    require APPPATH . 'modules/projects/views/_project_steps.php';
    ?>
</body>

</html>
