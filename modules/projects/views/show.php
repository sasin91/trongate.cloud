<?php
$is_finished = fn(object $d) => in_array($d->status, ['success', 'failed', 'rolled_back'], true);
$short_sha = fn(?string $sha) => $sha ? substr($sha, 0, 7) : '';
?>
<div class="content">
    <div class="back-link">
        <a href="dashboard" class="nav-link">&larr; Back to Dashboard</a>
    </div>

    <?= flashdata('<p class="flash">', '</p>') ?>

    <div class="card project-header">
        <div class="project-title">
            <div>
                <h2><?= htmlspecialchars($project->label) ?></h2>
                <p class="muted">
                    <?= htmlspecialchars(preg_replace('#^https?://(www\.)?github\.com/#', '', (string) $project->source_url)) ?>
                    &middot; <?= htmlspecialchars((string) $project->source_branch) ?>
                    <?php if (!empty($project->always_on)): ?>&middot; always on<?php else: ?>&middot; sleeps when idle<?php endif; ?>
                </p>
            </div>
            <div class="project-actions">
                <?= form_open('projects/' . $project->id . '/deploy') ?>
                    <button type="submit" class="btn-create">Deploy now</button>
                <?= form_close() ?>
                <?php if ($can_rollback): ?>
                    <?= form_open('projects/' . $project->id . '/rollback', ['onsubmit' => "return confirm('Roll back to the previous successful deployment?')"]) ?>
                        <button type="submit" class="btn-secondary">Roll back</button>
                    <?= form_close() ?>
                <?php endif; ?>
                <a href="projects/<?= $project->id ?>/settings" class="btn-secondary">Settings</a>
            </div>
        </div>

        <ul class="project-urls">
            <?php foreach ($urls as $url): ?>
                <li><a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($url) ?></a></li>
            <?php endforeach; ?>
        </ul>

        <div class="project-current">
            <?php if ($current === false): ?>
                <span class="muted">Not live yet. Your first deployment shows up below.</span>
            <?php else: ?>
                <span class="status-pill status-<?= htmlspecialchars($current->status) ?>"><?= htmlspecialchars(str_replace('_', ' ', $current->status)) ?></span>
                <span>Live: <a href="deployments/<?= $current->id ?>">#<?= $current->id ?></a></span>
                <?php if ($current->commit_sha): ?><code><?= htmlspecialchars($short_sha($current->commit_sha)) ?></code><?php endif; ?>
                <?php if ($current->completed_at): ?><span class="muted"><?= htmlspecialchars($current->completed_at) ?></span><?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($live_state !== null): ?>
            <div class="project-live" id="live-state" mx-get="projects/<?= $project->id ?>/live-state" mx-trigger="every 5s">
                <?php require __DIR__ . '/_live_state.php'; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="section-header">
        <h2>Deployments</h2>
    </div>

    <?php if (empty($deployments)): ?>
        <div class="card"><p class="muted">No deployments yet.</p></div>
    <?php else: ?>
        <div class="card table-card">
            <table class="deployments-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Status</th>
                        <th>Trigger</th>
                        <th>Commit</th>
                        <th>Started</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deployments as $deployment): ?>
                        <tr>
                            <td><a href="deployments/<?= $deployment->id ?>">#<?= $deployment->id ?></a></td>
                            <td>
                                <span class="status-pill status-<?= htmlspecialchars($deployment->status) ?>"><?= htmlspecialchars(str_replace('_', ' ', $deployment->status)) ?></span>
                                <?php if (!empty($deployment->is_current)): ?><span class="current-tag">live</span><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($deployment->trigger_type) ?></td>
                            <td><code><?= htmlspecialchars($short_sha($deployment->commit_sha)) ?></code></td>
                            <td class="muted"><?= htmlspecialchars($deployment->created_at) ?></td>
                            <td class="row-actions">
                                <?php if (empty($deployment->is_current) && $is_finished($deployment) && $deployment->status !== 'failed' && !empty($deployment->image)): ?>
                                    <?= form_open('projects/' . $project->id . '/rollback', ['onsubmit' => "return confirm('Roll back to deployment #" . (int) $deployment->id . "?')"]) ?>
                                        <?= form_hidden('deployment_id', (string) $deployment->id) ?>
                                        <button type="submit" class="btn-link">Roll back to this</button>
                                    <?= form_close() ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
