(function () {
    let lastLogId = 0;
    let isComplete = false;
    const statusPill = document.getElementById('deployment-status');
    const logsContent = document.getElementById('logs-content');
    const logContainer = document.getElementById('log-container');
    const ghPrompt = document.getElementById('github-actions-prompt');

    if (!statusPill) return;

    // The API URL is expected to be on the status pill as a data attribute
    const apiUrl = statusPill.dataset.apiUrl;

    if (!apiUrl) {
        console.error('API URL not found on status pill');
        return;
    }

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

        fetch(apiUrl + '?last_log_id=' + lastLogId)
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
