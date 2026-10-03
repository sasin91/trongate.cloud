<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Project - Trongate Cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>customer-onboarding_module/css/onboarding.css">
    <script src="<?= BASE_URL ?>js/trongate-mx.min.js"></script>
</head>

<body>

    <div class="onboarding-card">
        <div class="onboarding-header">
            <h1>Deploy your first app</h1>
            <p>Pick a repository. We build it and give it a live URL.</p>
        </div>

        <?= validation_errors() ?>

        <?= form_open('onboarding/submit_project') ?>

        <?php
        $branches_url = 'onboarding/branches';
        require APPPATH . 'modules/projects/views/_project_fields.php';
        ?>

        <div class="steps">
            <div class="step-dot completed"></div>
            <div class="step-dot completed"></div>
            <div class="step-dot active"></div>
        </div>

        <button type="submit" class="btn-primary">
            <div class="spinner" style="display: none;"></div>
            Create &amp; Deploy
        </button>
        <?= form_close() ?>
    </div>

    <script src="<?= BASE_URL ?>customer-onboarding_module/js/onboarding.js"></script>
    <script src="<?= BASE_URL ?>customer-onboarding_module/js/project.js"></script>
</body>

</html>
