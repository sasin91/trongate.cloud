<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= BASE_URL ?>css/trongate.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>customer_module/css/login.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>mcp-oauth_module/css/consent.css">
    <title>Connect <?= htmlspecialchars($provider_name) ?></title>
</head>

<body>
    <main class="consent">
        <h1>Connect <?= htmlspecialchars($provider_name) ?></h1>

        <?php if ($customer === false): ?>
            <p class="consent-lead"><?= htmlspecialchars($client_name) ?> wants to work with your trongate.cloud projects. Sign in first.</p>
            <?php if (GITHUB_APP_CLIENT_ID !== ''): ?>
                <a href="<?= BASE_URL ?>auth/github?then=oauth/consent" class="github-btn">Continue with GitHub</a>
            <?php endif; ?>
            <p class="consent-note">Signed up with an email and password? <a href="<?= BASE_URL ?>login">Sign in</a>, then connect again from <?= htmlspecialchars($provider_name) ?>.</p>
        <?php elseif (!$onboarded): ?>
            <p class="consent-lead">Finish setting up your account first, then connect again from <?= htmlspecialchars($provider_name) ?>.</p>
            <a href="<?= BASE_URL ?>onboarding" class="github-btn">Finish setup</a>
        <?php else: ?>
            <p class="consent-lead"><strong><?= htmlspecialchars($client_name) ?></strong> wants to act for
                <strong><?= htmlspecialchars((string) ($customer->github_login ?? $customer->email)) ?></strong> on trongate.cloud.</p>

            <p>It can do what you can do in your projects:</p>
            <ul class="consent-list">
                <li>See your projects, apps, deployments and logs</li>
                <li>Deploy, roll back and change app settings and environment variables</li>
                <li>Read your apps' databases, and change them when you approve it</li>
            </ul>
            <p class="consent-note">It cannot delete apps or projects, or manage members. Every database change is logged on the app's Database tab. You can disconnect it at any time under Claude in the control panel.</p>

            <?= form_open($form_location, ['class' => 'consent-actions']) ?>
            <button type="submit" name="decision" value="allow">Allow</button>
            <button type="submit" name="decision" value="deny" class="alt">Cancel</button>
            <?= form_close() ?>

            <p class="consent-note">You will be sent back to <?= htmlspecialchars($redirect_host) ?>.</p>
        <?php endif; ?>
    </main>
</body>

</html>
