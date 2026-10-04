<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=1440">
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="<?= BASE_URL ?>templates_module/css/customer.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>deployments_module/css/deployments.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>projects_module/css/projects.css">
    <title>App overview - trongate.cloud</title>
</head>
<body>
    <header>
        <div class="header-left">
            <span class="header-logo-link">
                <?php require APPPATH . 'modules/templates/views/_mark.php'; ?>
                <div class="header-title">trongate<span>.cloud</span></div>
            </span>
            <div class="header-separator"></div>
            <span class="nav-link">Acme</span>
        </div>
        <nav class="header-nav" style="display: flex; gap: 1.25rem; align-items: center;">
            <span class="nav-link">Dashboard</span>
            <span class="nav-link">Sign Out</span>
        </nav>
    </header>

    <main>
        <?php require APPPATH . 'modules/projects/views/_overview.php'; ?>
    </main>
</body>
</html>
