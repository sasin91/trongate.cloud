<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= BASE_URL ?>templates_module/css/customer.css">
    <?= $additional_includes_top ?? '' ?>
    <script src="<?= BASE_URL ?>js/trongate-mx.min.js"></script>
    <title>trongate.cloud</title>
</head>
<body>
    <?php if ($impersonation !== null): ?>
        <?php require __DIR__ . '/_impersonation_banner.php'; ?>
    <?php endif; ?>
    <header>
        <div class="header-left">
            <a href="dashboard" class="header-logo-link">
                <?php require __DIR__ . '/_mark.php'; ?>
                <div class="header-title">trongate<span>.cloud</span></div>
            </a>
            <?php if (!empty($crumbs)): ?>
                <div class="header-separator"></div>
                <nav class="header-crumbs" aria-label="Breadcrumb">
                    <?php foreach ($crumbs as $i => [$href, $label]): ?>
                        <?php if ($i > 0): ?><span class="header-crumb-sep" aria-hidden="true">/</span><?php endif; ?>
                        <a href="<?= htmlspecialchars($href) ?>" class="nav-link"<?= $i === count($crumbs) - 1 ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </div>
        <nav class="header-nav">
            <a href="dashboard" class="nav-link header-projects">Projects</a>
            <a href="integrations/claude" class="nav-link header-projects">Claude</a>
            <a href="docs" class="nav-link header-projects">Docs</a>
            <?php if ($impersonation === null): ?>
                <a href="logout" class="nav-link">Sign out</a>
            <?php endif; ?>
        </nav>
    </header>

    <main>
        <?= $view_content ?? display($data) ?>
    </main>

    <?= $additional_includes_btm ?? '' ?>
</body>
</html>
