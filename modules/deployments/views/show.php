<div class="content">
    <div style="margin-bottom: 1.5rem;">
        <a href="customer" style="color: var(--accent); text-decoration: none;">&larr; Back to Dashboard</a>
    </div>

    <div class="card" style="margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0;">Deployment #<?= $deployment->id ?></h2>
            <span class="status-pill status-<?= $deployment->status ?>" id="deployment-status">
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

<script src="<?= BASE_URL ?>js/trongate-mx.js"></script>
<script>
(function() {
    let lastLogId = 0;
    let isComplete = false;
    const statusPill = document.getElementById('deployment-status');
    const logsContent = document.getElementById('logs-content');
    const logContainer = document.getElementById('log-container');
    const ghPrompt = document.getElementById('github-actions-prompt');

    function formatTime(dateStr) {
        const date = new Date(dateStr);
        return date.toTimeString().split(' ')[0];
    }

    function addLogLine(log) {
        const line = document.createElement('div');
        line.className = 'log-line';
        line.setAttribute('data-log-id', log.id);
        line.style.marginBottom = '2px';

        const levelColors = {
            'info': '#58a6ff',
            'warn': '#d29922',
            'error': '#f85149'
        };

        line.innerHTML = `
            <span style="color: #666; margin-right: 8px;">${formatTime(log.created_at)}</span>
            <span style="color: ${levelColors[log.level] || '#58a6ff'}; margin-right: 8px; text-transform: uppercase; font-size: 0.75rem;">${log.level}</span>
            <span>${escapeHtml(log.message)}</span>
        `;

        return line;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function updateStatus(data) {
        // Update status pill
        const statusText = data.status.charAt(0).toUpperCase() + data.status.slice(1);
        statusPill.textContent = statusText;
        statusPill.className = 'status-pill status-' + data.status;

        // Add new logs
        if (data.logs && data.logs.length > 0) {
            // Remove "Waiting for logs..." placeholder if present
            const placeholder = logsContent.querySelector('div[style*="color: #666"]');
            if (placeholder && placeholder.textContent.includes('Waiting')) {
                placeholder.remove();
            }

            data.logs.forEach(log => {
                logsContent.appendChild(addLogLine(log));
                lastLogId = Math.max(lastLogId, parseInt(log.id));
            });

            // Scroll to bottom
            logContainer.scrollTop = logContainer.scrollHeight;
        }

        // Check if complete
        if (data.completed) {
            isComplete = true;
            ghPrompt.style.display = 'block';

            // Add completion message
            const completeMsg = document.createElement('div');
            completeMsg.style.marginTop = '1rem';
            completeMsg.style.padding = '0.5rem';
            completeMsg.style.borderTop = '1px solid #333';
            completeMsg.style.color = data.status === 'success' ? '#3fb950' : '#f85149';
            completeMsg.textContent = data.status === 'success'
                ? '=== Deployment completed successfully ==='
                : '=== Deployment failed ===';
            logsContent.appendChild(completeMsg);
            logContainer.scrollTop = logContainer.scrollHeight;
        }
    }

    function poll() {
        if (isComplete) return;

        fetch('<?= BASE_URL ?>deployments/status/<?= $deployment->id ?>?last_log_id=' + lastLogId)
            .then(response => response.json())
            .then(data => {
                updateStatus(data);
                if (!isComplete) {
                    setTimeout(poll, 2000);
                }
            })
            .catch(err => {
                console.error('Polling error:', err);
                if (!isComplete) {
                    setTimeout(poll, 5000);
                }
            });
    }

    // Start polling
    poll();
})();
</script>

<style>
.log-line {
    white-space: pre-wrap;
    word-break: break-all;
    line-height: 1.4;
}
</style>
