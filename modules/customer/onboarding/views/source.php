<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connect GitHub - trongate.cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>apps_module/css/wizard.css">
</head>

<body class="wz-body">
    <?php $q_current = 1; $q_total = 8; require APPPATH . 'modules/apps/views/_wizard_top.php'; ?>

    <div class="wz-wrap">
        <main class="wz-main">
            <div class="wz-stage">
                <div class="wz-q">
                    <span class="wz-num">1 &rarr;</span>
                    <h1>Welcome aboard. Let's connect GitHub.</h1>
                    <p>Sign in with GitHub and install the trongate.cloud app on the repositories you want to deploy. Pushes then deploy on their own.</p>
                </div>

                <?= flashdata('<div class="wz-errors" role="alert"><p>', '</p></div>') ?>

                <div class="wz-help">
                    <strong>What we can see</strong>
                    <p>Only the repositories you pick when you install the app, read-only. You can change that selection on GitHub at any time.</p>
                </div>

                <div class="wz-nav">
                    <a href="<?= htmlspecialchars($connect_url) ?>" class="wz-ok">Connect GitHub</a>
                    <a href="<?= htmlspecialchars($public_link_url) ?>" class="wz-back">Deploy a public link instead</a>
                </div>
            </div>
        </main>
    </div>

    <script src="<?= BASE_URL ?>customer-onboarding_module/js/onboarding.js"></script>
</body>

</html>
