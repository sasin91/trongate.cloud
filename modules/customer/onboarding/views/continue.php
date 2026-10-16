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
    <?php $q_current = 2; $q_total = 9; require APPPATH . 'modules/apps/views/_wizard_top.php'; ?>
    <?php require APPPATH . 'modules/apps/views/_wizard_continue.php'; ?>
</body>

</html>
