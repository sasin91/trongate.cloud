<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/trongate.css">
    <link rel="stylesheet" href="css/trongate-icons.css">
    <link rel="stylesheet" href="customer_module/css/index.css">
    <title>Dashboard - <?= WEBSITE_NAME ?></title>
</head>

<body>
    <aside>
        <div class="sidebar-header">
            <span style="color: var(--accent);">●</span>
            Trongate Cloud
        </div>
        <nav class="sidebar-nav">
            <a href="customer" class="nav-item active">
                <i class="tg tg-home"></i> Dashboard
            </a>
            <a href="projects" class="nav-item">
                <i class="tg tg-shopping-basket"></i> Projects
            </a>
            <a href="servers" class="nav-item">
                <i class="tg tg-sign-in"></i> Servers
            </a>
            <a href="deployments" class="nav-item">
                <i class="tg tg-sign-out"></i> Deployments
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="customer/logout" class="nav-item" style="margin-bottom: 0;">
                <i class="tg tg-sign-out"></i> Sign Out
            </a>
        </div>
    </aside>

    <main>
        <header>
            <div class="header-title">Dashboard Overview</div>
            <div class="user-nav">
                <button class="btn-create">Quick Deploy</button>
            </div>
        </header>

        <div class="content">
            <!-- Projects Section -->
            <div class="section-header">
                <h2>Recent Projects</h2>
                <a href="projects/create" class="btn-create">+ New Project</a>
            </div>

            <div class="dash-grid">
                <?php if (count($applications) > 0): ?>
                    <?php foreach ($applications as $project): ?>
                        <a href="projects/view/<?= $project->id ?>" class="card">
                            <h3><?= $project->label ?></h3>
                            <div class="card-meta">
                                <span><?= ucfirst($project->type) ?></span>
                                <span>•</span>
                                <span><?= date('M j, Y', strtotime($project->created_at)) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="card" style="grid-column: 1 / -1;">
                        <div class="empty-state">No projects yet. Create your first project to get started.</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Servers Section -->
            <div class="section-header">
                <h2>Active Servers</h2>
                <a href="servers/create" class="btn-create">+ Add Server</a>
            </div>

            <div class="dash-grid">
                <?php if (count($servers) > 0): ?>
                    <?php foreach ($servers as $server): ?>
                        <a href="servers/view/<?= $server->id ?>" class="card">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <h3><?= $server->address ?? 'Pending IP...' ?></h3>
                                <span class="status-pill status-<?= $server->status ?>"><?= $server->status ?></span>
                            </div>
                            <div class="card-meta">
                                <span><?= ucfirst($server->provider) ?></span>
                                <span>•</span>
                                <span><?= $server->region ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="card" style="grid-column: 1 / -1;">
                        <div class="empty-state">No active servers. Provision a server to deploy your apps.</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Deployments -->
            <div class="section-header">
                <h2>Recent Deployments</h2>
                <a href="deployments" style="font-size: 0.875rem; color: var(--accent); text-decoration: none;">View All activity</a>
            </div>

            <div class="activity-feed">
                <?php if (count($deployments) > 0): ?>
                    <?php foreach ($deployments as $deployment): ?>
                        <div class="activity-item">
                            <div class="activity-main">
                                <div class="activity-icon">
                                    <i class="tg tg-sign-out"></i>
                                </div>
                                <div class="activity-content">
                                    <p>Deployed <strong><?= $deployment->project_label ?></strong> to <strong><?= $deployment->server_address ?></strong></p>
                                    <span><?= date('M j, Y \a\t H:i', strtotime($deployment->created_at)) ?></span>
                                </div>
                            </div>
                            <span class="status-pill status-<?= $deployment->status ?>"><?= $deployment->status ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No recent deployments.</div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>

</html>