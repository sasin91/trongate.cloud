<div class="container" style="padding-top: 2rem;">
    <div style="margin-bottom: 2rem;">
        <a href="customer" class="nav-link">&larr; Back to Dashboard</a>
    </div>

    <h1>Project Details</h1>
    
    <div class="card" style="margin-bottom: 2rem;">
        <h3><?= $project->label ?></h3>
        <!-- Placeholder for project details -->
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h2>Servers</h2>
            <button onclick="document.getElementById('add-server-dialog').showModal()" class="btn-create">+ Attach Server</button>
    </div>

    <div class="card">
        <p style="color: var(--text-muted); margin: 0;">No servers attached.</p>
    </div>

    <dialog id="add-server-dialog">
        <h3 style="margin-top: 0;">Attach New Server</h3>
        <p style="color: var(--text-muted);">Select a server to attach to this project.</p>
        <form method="dialog">
            <div class="form-group">
                <select class="form-select">
                    <option>Server 1 (192.168.1.1)</option>
                </select>
                <div style="margin-top: 0.5rem; font-size: 0.875rem;">
                    Don't see your server? <a href="servers/create?label=<?= urlencode($project->label . '-server') ?>" style="color: var(--primary); text-decoration: none;">Provision a new server</a>
                </div>
            </div>
            <div style="margin-top: 1rem; text-align: right; display: flex; gap: 1rem; justify-content: flex-end;">
                <button class="btn-secondary">Cancel</button>
                <button class="btn-primary">Attach</button>
            </div>
        </form>
    </dialog>
</div>
