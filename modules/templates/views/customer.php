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
    <!-- View Transitions -->
    <style>
        @view-transition { navigation: auto; }
    </style>
</head>
<body>
    <header>
        <div style="display: flex; align-items: center; gap: 1.5rem;">
            <a href="customer" style="text-decoration: none;">
                <div class="header-title">Trongate Cloud</div>
            </a>
            <?php if (!empty($teams) && count($teams) > 1): ?>
                <form action="customer/switch_team" method="post" style="margin: 0;">
                    <select name="team_id" onchange="this.form.submit()" class="form-select" style="padding: 0.375rem 2rem 0.375rem 0.75rem; font-size: 0.8125rem; border-radius: 6px;">
                        <?php foreach($teams as $team): ?>
                            <option value="<?= $team->id ?>" <?= ($team->id == $current_team_id) ? 'selected' : '' ?>><?= htmlspecialchars($team->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php elseif (!empty($teams)): ?>
                <span style="font-size: 0.875rem; color: var(--text-muted);"><?= htmlspecialchars($teams[0]->name) ?></span>
            <?php endif; ?>
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
