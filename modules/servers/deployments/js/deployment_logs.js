
class DeploymentLogs extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this.eventSource = null;
    }

    connectedCallback() {
        const url = this.getAttribute('url');
        if (!url) {
            console.error('DeploymentLogs: Missing url attribute');
            return;
        }

        this.render();
        this.startStreaming(url);
    }

    disconnectedCallback() {
        if (this.eventSource) {
            this.eventSource.close();
        }
    }

    startStreaming(url) {
        this.eventSource = new EventSource(url);
        const output = this.shadowRoot.getElementById('output');
        const statusEl = this.shadowRoot.getElementById('status');

        this.eventSource.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                if (data.text) {
                    const span = document.createElement('span');
                    span.textContent = data.text;
                    output.appendChild(span);

                    // Auto-scroll
                    const terminal = this.shadowRoot.querySelector('.terminal');
                    terminal.scrollTop = terminal.scrollHeight;
                }
            } catch (e) {
                console.error('Error parsing log data:', e);
            }
        };

        this.eventSource.addEventListener('close', (event) => {
            const data = JSON.parse(event.data);
            statusEl.textContent = `Deployment ${data.status}`;
            statusEl.className = `status badge ${data.status}`;
            this.eventSource.close();
        });

        this.eventSource.onerror = (err) => {
            console.error('EventSource failed:', err);
            // Optionally close on error or let it retry
            // this.eventSource.close();
        };
    }

    render() {
        this.shadowRoot.innerHTML = `
            <style>
                :host {
                    display: block;
                    font-family: 'Courier New', Courier, monospace;
                    margin: 20px 0;
                }
                .terminal {
                    background-color: #1e1e1e;
                    color: #d4d4d4;
                    padding: 15px;
                    border-radius: 6px;
                    height: 400px;
                    overflow-y: auto;
                    white-space: pre-wrap;
                    font-size: 14px;
                    line-height: 1.4;
                    border: 1px solid #333;
                }
                .header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 10px;
                    font-family: system-ui, -apple-system, sans-serif;
                }
                .title {
                    font-weight: 600;
                    color: #333;
                }
                .status {
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-size: 12px;
                    font-weight: 600;
                    text-transform: uppercase;
                }
                .status.pending { background: #e2e8f0; color: #475569; }
                .status.running { background: #dbeafe; color: #1e40af; }
                .status.success { background: #dcfce7; color: #166534; }
                .status.failed { background: #fee2e2; color: #991b1b; }
            </style>
            <div class="header">
                <span class="title">Deployment Logs</span>
                <span id="status" class="status running">Streaming...</span>
            </div>
            <div class="terminal">
                <div id="output"></div>
            </div>
        `;
    }
}

customElements.define('deployment-logs', DeploymentLogs);
