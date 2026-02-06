<div class="content">
    <div style="margin-bottom: 1.5rem;">
        <a href="customer" style="color: var(--accent); text-decoration: none;">&larr; Back to Dashboard</a>
    </div>

    <div class="card" style="margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0;">Deployment #<?= $deployment->id ?></h2>
            <span class="status-pill status-<?= $deployment->status ?>" 
                  id="deployment-status"
                  data-api-url="<?= BASE_URL ?>deployments/status/<?= $deployment->id ?>">
                <?= ucfirst($deployment->status) ?>
            </span>
        </div>
        <p style="color: var(--text-muted); margin-top: 0.5rem; margin-bottom: 0;">
            Source: <?= htmlspecialchars($deployment->source_url) ?>
        </p>
    </div>

    <h3>Deployment Logs</h3>
    <div id="log-container"
         class="card"
         style="background: #1a1a2e; color: #d4d4d4; font-family: 'Monaco', 'Menlo', monospace; font-size: 0.85rem; padding: 1rem; max-height: 500px; overflow-y: auto; min-height: 200px;">
        <div id="logs-content">
            <div style="color: #666;">Waiting for logs...</div>
        </div>
    </div>

    <div id="github-actions-prompt" style="display: none; margin-top: 1.5rem;">
        <div class="card" style="background: #f0f9ff; border: 1px solid #0284c7;">
            <h4 style="margin-top: 0; color: #0284c7;">Set Up Automatic Deployments</h4>
            <p>Configure GitHub Actions to automatically deploy on every push.</p>
            <a href="deployments/github_actions/<?= $deployment->id ?>" class="btn-create">
                View Setup Instructions
            </a>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?= BASE_URL ?>deployments_module/css/deployments.css">
<script src="<?= BASE_URL ?>deployments_module/js/deployments.js"></script>
