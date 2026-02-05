<div class="content" style="max-width: 800px;">
    <div style="margin-bottom: 1.5rem;">
        <a href="deployments/show/<?= $deployment->id ?>" style="color: var(--accent); text-decoration: none;">&larr; Back to Deployment</a>
    </div>

    <h1 style="margin-bottom: 0.5rem;">Configure GitHub Actions</h1>
    <p style="color: var(--text-muted); margin-bottom: 2rem;">
        Set up automatic deployments when you push to your repository.
    </p>

    <div class="card" style="margin-bottom: 1.5rem;">
        <h3 style="margin-top: 0;">Step 1: Add Workflow File</h3>
        <p>Create <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;">.github/workflows/deploy.yml</code> in your repository:</p>

        <pre style="background: #1a1a2e; color: #d4d4d4; padding: 1rem; border-radius: 8px; overflow-x: auto; font-size: 0.85rem;"><code>name: Deploy to Trongate Cloud

on:
  push:
    branches: [<?= htmlspecialchars($project->repository_branch ?? 'main') ?>]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Trigger Deployment
        run: |
          curl -X POST \
            -H "Content-Type: application/json" \
            -H "X-GitHub-Event: push" \
            -H "Authorization: Bearer ${{ secrets.TRONGATE_DEPLOY_TOKEN }}" \
            -d '{
              "ref": "${{ github.ref }}",
              "repository": {
                "full_name": "${{ github.repository }}"
              },
              "head_commit": {
                "id": "${{ github.sha }}",
                "message": "${{ github.event.head_commit.message }}"
              }
            }' \
            <?= htmlspecialchars($webhook_url) ?></code></pre>

        <button onclick="copyWorkflow()" class="btn-create" style="margin-top: 1rem; background: white; color: var(--accent); border: 1px solid var(--accent);">
            Copy Workflow
        </button>
    </div>

    <div class="card" style="margin-bottom: 1.5rem;">
        <h3 style="margin-top: 0;">Step 2: Add Repository Secret</h3>
        <p>In your GitHub repository settings, add a secret:</p>

        <ol style="margin: 1rem 0; padding-left: 1.5rem;">
            <li>Go to your repository on GitHub</li>
            <li>Click <strong>Settings</strong> &rarr; <strong>Secrets and variables</strong> &rarr; <strong>Actions</strong></li>
            <li>Click <strong>New repository secret</strong></li>
            <li>Name: <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;">TRONGATE_DEPLOY_TOKEN</code></li>
            <li>Value: Your Trongate Cloud API token (coming soon)</li>
        </ol>

        <div style="background: #fef3c7; border: 1px solid #f59e0b; padding: 1rem; border-radius: 8px; margin-top: 1rem;">
            <strong style="color: #92400e;">Note:</strong>
            <span style="color: #78350f;">Webhook authentication is not yet implemented. For now, deployments must be triggered manually from the dashboard.</span>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-top: 0;">Step 3: Test</h3>
        <p>Once configured, push a commit to your <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($project->repository_branch ?? 'main') ?></code> branch. The workflow will trigger a deployment automatically.</p>

        <div style="margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px;">
            <strong>Webhook URL:</strong><br>
            <code style="font-size: 0.85rem; word-break: break-all;"><?= htmlspecialchars($webhook_url) ?></code>
        </div>
    </div>

    <div style="margin-top: 2rem; text-align: center;">
        <a href="customer" class="btn-create">Return to Dashboard</a>
    </div>
</div>

<script>
function copyWorkflow() {
    const workflow = `name: Deploy to Trongate Cloud

on:
  push:
    branches: [<?= htmlspecialchars($project->repository_branch ?? 'main') ?>]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Trigger Deployment
        run: |
          curl -X POST \\
            -H "Content-Type: application/json" \\
            -H "X-GitHub-Event: push" \\
            -H "Authorization: Bearer \${{ secrets.TRONGATE_DEPLOY_TOKEN }}" \\
            -d '{
              "ref": "\${{ github.ref }}",
              "repository": {
                "full_name": "\${{ github.repository }}"
              },
              "head_commit": {
                "id": "\${{ github.sha }}",
                "message": "\${{ github.event.head_commit.message }}"
              }
            }' \\
            <?= htmlspecialchars($webhook_url) ?>`;

    navigator.clipboard.writeText(workflow).then(() => {
        alert('Workflow copied to clipboard!');
    }).catch(err => {
        console.error('Failed to copy:', err);
    });
}
</script>
