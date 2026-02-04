<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= BASE_URL ?>templates_module/css/customer.css">
    <?= $additional_includes_top ?? '' ?>
    <script src="<?= BASE_URL ?>js/trongate-mx.min.js"></script>
    <title>Trongate Cloud</title>
</head>
<body>
    <header>
        <div class="header-left">
            <a href="customer" class="header-logo-link">
                <div class="header-title">Trongate Cloud</div>
            </a>
            <div class="header-separator"></div>
            <form action="customer/switch_team" method="post" class="team-switcher-form">
                <select name="team_id" onchange="this.form.submit()" class="form-select team-switcher-select">
                    <?php foreach($teams as $team): ?>
                        <option value="<?= $team->id ?>" <?= ($team->id == $current_team_id) ? 'selected' : '' ?>><?= htmlspecialchars($team->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <nav>
            <a href="customer/logout" class="nav-link">Sign Out</a>
        </nav>
    </header>

    <main>
        <?= $view_content ?? display($data) ?>
    </main>

    <?= $additional_includes_btm ?? '' ?>
</body>
</html>
