<?php require __DIR__ . '/head.php'; ?>
<body>
    <main class="error-page">
        <p class="status">404</p>
        <h1><?= htmlspecialchars($report->title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($report->message, ENT_QUOTES, 'UTF-8') ?></p>
        <p>
            <a class="button" href="<?= htmlspecialchars($home, ENT_QUOTES, 'UTF-8') ?>">Go to the front page</a>
            <button type="button" class="button alt" onclick="history.back()">Go back</button>
        </p>
    </main>
</body>
</html>
