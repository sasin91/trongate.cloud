<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Name your project - trongate.cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>apps_module/css/wizard.css">
</head>

<body class="wz-body">
    <?php $q_current = 1; $q_total = 6; require APPPATH . 'modules/apps/views/_wizard_top.php'; ?>

    <div class="wz-wrap">
        <main class="wz-main">
            <?= form_open('onboarding/submit_project', ['class' => 'wz-stage']) ?>
                <div class="wz-q">
                    <span class="wz-num">1 &rarr;</span>
                    <h1>Welcome aboard. What's your project called?</h1>
                    <p>Your apps live in a project. You can invite people to it later.</p>
                </div>

                <?php $errors = validation_errors('<p>', '</p>'); ?>
                <?php if ($errors): ?><div class="wz-errors" role="alert"><?= $errors ?></div><?php endif; ?>

                <label class="wz-field">
                    <span class="wz-sr">Project name</span>
                    <input type="text" name="name" class="wz-big" placeholder="Acme Studio" value="<?= htmlspecialchars(post('name') ?: ($project_name ?? '')) ?>" required minlength="3" maxlength="50" autofocus>
                </label>

                <div class="wz-nav">
                    <button type="submit" class="wz-ok">OK</button>
                    <span class="wz-enter">press <strong>Enter &#8629;</strong></span>
                </div>
            <?= form_close() ?>
        </main>
    </div>

    <script src="<?= BASE_URL ?>customer-onboarding_module/js/onboarding.js"></script>
</body>

</html>
