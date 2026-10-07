<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= BASE_URL ?>css/trongate.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>customer_module/css/login.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>mcp-oauth_module/css/consent.css">
    <title>Could not connect</title>
</head>

<body>
    <main class="consent">
        <h1>Could not connect</h1>
        <p class="consent-lead"><?= htmlspecialchars($message) ?></p>
        <a href="<?= BASE_URL ?>dashboard" class="github-btn">Go to your projects</a>
    </main>
</body>

</html>
