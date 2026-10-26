<?php require __DIR__ . '/head.php'; ?>
<body>
    <main class="error-page">
        <p class="status"><?= (int) $report->status ?></p>
        <h1><?= htmlspecialchars($report->title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($report->message, ENT_QUOTES, 'UTF-8') ?></p>
        <p>
            <button type="button" class="button" onclick="location.reload()">Try again</button>
            <a class="button alt" href="<?= htmlspecialchars($home, ENT_QUOTES, 'UTF-8') ?>">Go to the front page</a>
        </p>
<?php if ($report->detail !== '') { ?>
        <p><small>Shown because ENV is dev and you're on this machine:</small></p>
        <pre><?= htmlspecialchars($report->detail, ENT_QUOTES, 'UTF-8') ?></pre>
<?php } ?>
    </main>
</body>
</html>
