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
    <header>
        <div class="header-left">
            <a href="dashboard" class="header-logo-link">
                <?php require __DIR__ . '/_mark.php'; ?>
                <div class="header-title">trongate<span>.cloud</span></div>
            </a>
            <div class="header-separator"></div>
            <?= form_open('switch-project', ['class' => 'project-switcher-form']) ?>
                <select name="project_id" onchange="this.form.submit()" class="form-select project-switcher-select">
                    <?php foreach($projects as $project): ?>
                        <option value="<?= $project->id ?>" <?= ($project->id == $current_project_id) ? 'selected' : '' ?>><?= htmlspecialchars($project->name) ?></option>
                    <?php endforeach; ?>
                </select>
            <?= form_close() ?>
        </div>
        <nav class="header-nav" style="display: flex; gap: 1.25rem; align-items: center;">
            <a href="dashboard" class="nav-link">Dashboard</a>
            <a href="logout" class="nav-link">Sign Out</a>
        </nav>
    </header>

    <main>
        <?= $view_content ?? display($data) ?>
    </main>

    <?= $additional_includes_btm ?? '' ?>
</body>
</html>
