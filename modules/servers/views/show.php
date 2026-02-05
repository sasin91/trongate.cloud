<div class="server-header">
    <div class="server-title">
        <h1><?= htmlspecialchars($server->label) ?></h1>
        <span class="status-badge status-<?= $server->status ?>"><?= ucfirst($server->status) ?></span>
    </div>
    <div class="server-actions">
        <?php if ($is_ready): ?>
            <button onclick="openDeployModal()" class="btn btn-primary">
                <i class="fa fa-rocket"></i> Deploy
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="server-grid">
    <div class="card server-stats">
        <h3>Server Info</h3>
        <div class="stats-grid">
            <div class="stat-item">
                <span class="stat-label">IP Address</span>
                <span class="stat-value"><?= htmlspecialchars($server->address) ?></span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Location</span>
                <span class="stat-value"><?= htmlspecialchars($server->region) ?></span>
            </div>
             <div class="stat-item">
                <span class="stat-label">Type</span>
                <span class="stat-value"><?= htmlspecialchars($server->type) ?></span>
            </div>
             <div class="stat-item">
                <span class="stat-label">Image</span>
                <span class="stat-value"><?= htmlspecialchars($server->image) ?></span>
            </div>
        </div>
    </div>

    <div class="card server-projects">
        <h3>Projects</h3>
        <?php if (empty($server_projects)): ?>
            <div class="empty-state">
                No projects deployed yet.
            </div>
        <?php else: ?>
            <div class="projects-list">
                <?php foreach($server_projects as $project): ?>
                    <div class="project-item">
                        <div class="project-info">
                            <span class="project-name"><?= htmlspecialchars($project->label) ?></span>
                            <span class="project-type badge-secondary"><?= htmlspecialchars($project->type) ?></span>
                        </div>
                        <a href="servers/show/<?= $server->id ?>?project_id=<?= $project->id ?>" class="btn-sm btn-secondary">History</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card server-deployments">
    <h3>Deployment History</h3>
    <?php if (empty($deployments)): ?>
        <div class="empty-state">
            No deployments found.
        </div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Project</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($deployments as $deployment): ?>
                    <?php 
                        $metadata = json_decode($deployment->metadata ?? '{}', true);
                        $branch = $metadata['branch'] ?? '-';
                    ?>
                    <tr>
                        <td><?= date('M j, Y H:i', strtotime($deployment->created_at)) ?></td>
                        <td><?= htmlspecialchars($deployment->project_label ?? 'Unknown') ?></td>
                        <td><span class="branch-badge"><?= htmlspecialchars($branch) ?></span></td>
                        <td>
                            <span class="status-dot status-<?= $deployment->status ?>"></span>
                            <?= ucfirst($deployment->status) ?>
                        </td>
                        <td>
                            <a href="deployments/show/<?= $deployment->id ?>" class="btn-text">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Deploy Modal -->
<dialog id="deployModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Deploy to Server</h3>
            <button class="close-modal" onclick="closeDeployModal()">&times;</button>
        </div>
        <?= form_open('servers/deploy') ?>
            <input type="hidden" name="server_id" value="<?= $server->id ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label>Select Project</label>
                    <select name="project_id" class="form-select" required>
                        <option value="">Choose a project...</option>
                        <?php foreach($all_projects as $project): ?>
                            <option value="<?= $project->id ?>" <?= $project_id == $project->id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($project->label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeployModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Deploy Now</button>
            </div>
        <?= form_close() ?>
    </div>
</dialog>
