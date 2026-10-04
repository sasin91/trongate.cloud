<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connect GitHub - trongate.cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>projects_module/css/wizard.css">
</head>

<body class="wz-body">
    <?php $q_current = 1; $q_total = 5; require APPPATH . 'modules/projects/views/_wizard_top.php'; ?>

    <div class="wz-wrap">
        <main class="wz-main">
            <?= form_open('onboarding/submit_source', ['class' => 'wz-stage']) ?>
                <div class="wz-q">
                    <span class="wz-num">1 &rarr;</span>
                    <h1>Welcome aboard. Let's connect GitHub.</h1>
                    <p>Paste a personal access token so we can list your repositories and set up deploys.</p>
                </div>

                <?php $errors = validation_errors('<p>', '</p>'); ?>
                <?php if ($errors): ?><div class="wz-errors" role="alert"><?= $errors ?></div><?php endif; ?>

                <input type="hidden" name="provider" value="github">

                <label class="wz-field">
                    <span class="wz-label">Personal access token</span>
                    <input type="password" name="token" class="wz-in mono" placeholder="ghp_..." required autocomplete="off" autofocus>
                </label>

                <div class="wz-help">
                    <strong>Getting a token takes a minute</strong>
                    <ol>
                        <li>Open <a href="https://github.com/settings/tokens" target="_blank" rel="noopener">GitHub settings, Tokens (classic)</a>.</li>
                        <li>Choose <strong>Generate new token (classic)</strong>.</li>
                        <li>Tick the <code>repo</code> scope, generate, and paste it here.</li>
                    </ol>
                </div>

                <div class="wz-nav">
                    <button type="submit" class="wz-ok">Connect</button>
                    <span class="wz-enter">press <strong>Enter &#8629;</strong></span>
                </div>
            <?= form_close() ?>
        </main>
    </div>

    <script src="<?= BASE_URL ?>customer-onboarding_module/js/onboarding.js"></script>
</body>

</html>
