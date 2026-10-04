<?php
/**
 * App overview: header, environment map, deployments and side cards.
 * Shared by projects/show and the landing page's product shot.
 *
 * Expects: $project, $urls, $current (deployment|false), $deployments, $can_rollback,
 * $live_state (array|null), $database_name, and optionally $preview (true: no forms, no polling).
 */
$preview = $preview ?? false;
$e = fn($value) => htmlspecialchars((string) $value);
$short_sha = fn(?string $sha) => $sha ? substr($sha, 0, 7) : '';
$is_finished = fn(object $d) => in_array($d->status, ['success', 'failed', 'rolled_back'], true);
$repository = preg_replace('#^https?://(www\.)?github\.com/#', '', (string) $project->source_url);
$auto_deploy = !isset($project->auto_deploy) || !empty($project->auto_deploy);

// The custom domain's progress: verify ownership, point DNS here, get a certificate
$domain = (string) ($project->domain ?? '');
$domain_state = null;
if ($domain !== '') {
    if ((string) ($project->verified_domain ?? '') !== $domain) {
        $domain_state = ['label' => 'Needs verifying', 'class' => 'warn', 'hint' => 'Add the TXT record from settings to prove you own it.'];
    } elseif (empty($project->domain_pointed_at)) {
        $domain_state = ['label' => 'Waiting for DNS', 'class' => 'warn', 'hint' => 'Point a CNAME at ' . PLATFORM_APPS_DOMAIN . '. It goes live by itself once it resolves.'];
    } elseif (empty($project->domain_secured_at)) {
        $domain_state = ['label' => 'Issuing certificate', 'class' => 'warn', 'hint' => 'It points here. HTTPS switches on once the certificate is issued.'];
    } else {
        $domain_state = ['label' => 'HTTPS', 'class' => 'ok', 'hint' => ''];
    }
}
$domain_count = $domain === '' ? 1 : 2;
$platform_host = $project->slug . '.' . PLATFORM_APPS_DOMAIN;
?>
<div class="ov">
    <div class="ov-head">
        <div class="ov-title">
            <div class="ov-title-row">
                <h1><?= $e($project->label) ?></h1>
                <span class="ov-live" id="live-state"<?php if (!$preview && $live_state !== null): ?> mx-get="projects/<?= (int) $project->id ?>/live-state" mx-trigger="every 5s"<?php endif; ?>>
                    <?php if ($live_state !== null): ?>
                        <?php require __DIR__ . '/_live_state.php'; ?>
                    <?php else: ?>
                        <span class="status-pill status-pending">not live yet</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="ov-meta">
                <a href="<?= $e($urls[count($urls) - 1]) ?>" target="_blank" rel="noopener" class="ov-mono"><?= $e(preg_replace('#^https://#', '', $urls[count($urls) - 1])) ?></a>
                <span><?= $e($repository) ?> &middot; <?= $e($project->source_branch) ?></span>
                <span><?= $auto_deploy ? 'Deploys on push' : 'Manual deploys' ?></span>
            </div>
        </div>
        <div class="ov-actions">
            <?php if ($preview): ?>
                <span class="btn-secondary">Roll back</span>
                <span class="btn-primary">Deploy now</span>
            <?php else: ?>
                <?php if ($can_rollback): ?>
                    <?= form_open('projects/' . $project->id . '/rollback', ['onsubmit' => "return confirm('Roll back to the previous successful deployment?')"]) ?>
                        <button type="submit" class="btn-secondary">Roll back</button>
                    <?= form_close() ?>
                <?php endif; ?>
                <?= form_open('projects/' . $project->id . '/deploy') ?>
                    <button type="submit" class="btn-primary">Deploy now</button>
                <?= form_close() ?>
            <?php endif; ?>
        </div>
    </div>

    <nav class="ov-tabs" aria-label="App sections">
        <a href="projects/<?= (int) $project->id ?>" aria-current="page">Overview</a>
        <a href="projects/<?= (int) $project->id ?>#deployments">Deployments</a>
        <a href="projects/<?= (int) $project->id ?>/settings">Settings</a>
    </nav>

    <?php if (!$preview): ?>
        <?= flashdata('<p class="flash">', '</p>') ?>
    <?php endif; ?>

    <div class="ov-main">
        <div class="ov-col">
            <section class="ov-card" aria-labelledby="env-h">
                <div class="ov-card-head">
                    <h2 id="env-h">Environment</h2>
                    <span class="muted">production</span>
                </div>
                <div class="ov-canvas">
                    <div class="ov-env">
                        <div class="ov-node">
                            <span class="ov-node-kind">Source</span>
                            <strong><?= $e($repository) ?></strong>
                            <span class="muted">Branch <?= $e($project->source_branch) ?><?= $auto_deploy ? ' &middot; push to deploy' : '' ?></span>
                        </div>
                        <div class="ov-link" aria-hidden="true"></div>
                        <div class="ov-node ov-node-app">
                            <span class="ov-node-kind">App</span>
                            <strong><?= $e($project->label) ?></strong>
                            <div class="ov-chips">
                                <span><?= !empty($project->always_on) ? 'Always on' : 'Sleeps when idle' ?></span>
                                <span><?= $e(['trongate_v2' => 'Trongate v2', 'trongate_v1' => 'Trongate v1'][$project->type] ?? $project->type) ?></span>
                            </div>
                        </div>
                        <div class="ov-link" aria-hidden="true"></div>
                        <div class="ov-node">
                            <span class="ov-node-kind">Domains</span>
                            <strong><?= $domain_count ?> <?= $domain_count === 1 ? 'domain' : 'domains' ?></strong>
                            <?php if ($domain_state !== null && $domain_state['class'] === 'warn'): ?>
                                <span class="ov-warn-text"><?= $e($domain_state['label']) ?></span>
                            <?php else: ?>
                                <span class="muted">All on HTTPS</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ov-db-wrap">
                        <div class="ov-link-v" aria-hidden="true"></div>
                        <div class="ov-node ov-node-db">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
                            <div>
                                <span class="ov-node-kind">Database</span>
                                <strong class="ov-mono"><?= $e($database_name) ?></strong>
                                <span class="muted">MariaDB</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ov-card" id="deployments" aria-labelledby="dep-h">
                <div class="ov-card-head">
                    <h2 id="dep-h">Deployments</h2>
                </div>
                <?php if (empty($deployments)): ?>
                    <p class="ov-empty muted">No deployments yet.</p>
                <?php else: ?>
                    <div class="ov-table-wrap">
                        <table class="deployments-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Commit</th>
                                    <th>Trigger</th>
                                    <th>Started</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deployments as $deployment): ?>
                                    <tr>
                                        <td>
                                            <a href="deployments/<?= (int) $deployment->id ?>" class="status-pill status-<?= $e($deployment->status) ?>"><?= $e(str_replace('_', ' ', $deployment->status)) ?></a>
                                            <?php if (!empty($deployment->is_current)): ?><span class="current-tag">live</span><?php endif; ?>
                                        </td>
                                        <td><?php if ($deployment->commit_sha): ?><code class="ov-sha"><?= $e($short_sha($deployment->commit_sha)) ?></code><?php endif; ?></td>
                                        <td class="muted"><?= $e(str_replace('_', ' ', $deployment->trigger_type)) ?></td>
                                        <td class="muted"><?= $e($deployment->created_at) ?></td>
                                        <td class="row-actions">
                                            <?php if (!$preview && empty($deployment->is_current) && $is_finished($deployment) && $deployment->status !== 'failed' && !empty($deployment->image)): ?>
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
            </section>
        </div>

        <div class="ov-col">
            <section class="ov-card ov-pad" aria-labelledby="live-h">
                <h2 id="live-h">Live deployment</h2>
                <?php if ($current === false): ?>
                    <p class="muted">Not live yet. Your first deployment shows up on the left.</p>
                <?php else: ?>
                    <p class="ov-current">
                        <code class="ov-sha"><?= $e($short_sha($current->commit_sha)) ?></code>
                        <span class="muted"><?= $e(str_replace('_', ' ', $current->trigger_type)) ?><?= $current->completed_at ? ' &middot; ' . $e($current->completed_at) : '' ?></span>
                    </p>
                    <p class="ov-links">
                        <a href="deployments/<?= (int) $current->id ?>">Build log</a>
                        <?php if ($current->commit_sha && str_starts_with((string) $project->source_url, 'https://github.com/')): ?>
                            <a href="<?= $e(rtrim((string) $project->source_url, '/') . '/commit/' . $current->commit_sha) ?>" target="_blank" rel="noopener">View on GitHub</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </section>

            <section class="ov-card" aria-labelledby="dom-h">
                <div class="ov-card-head">
                    <h2 id="dom-h">Domains</h2>
                    <a href="projects/<?= (int) $project->id ?>/settings"><?= $domain === '' ? 'Add domain' : 'Manage' ?></a>
                </div>
                <div class="ov-domain">
                    <span class="ov-mono"><?= $e($platform_host) ?></span>
                    <span class="ov-badge ok">HTTPS</span>
                </div>
                <?php if ($domain_state !== null): ?>
                    <div class="ov-domain">
                        <span class="ov-mono"><?= $e($domain) ?></span>
                        <span class="ov-badge <?= $domain_state['class'] ?>"><?= $e($domain_state['label']) ?></span>
                    </div>
                    <?php if ($domain_state['hint'] !== ''): ?>
                        <p class="ov-domain-hint muted"><?= $e($domain_state['hint']) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="ov-card" aria-labelledby="set-h">
                <div class="ov-card-head">
                    <h2 id="set-h">Quick settings</h2>
                    <a href="projects/<?= (int) $project->id ?>/settings">Edit</a>
                </div>
                <dl class="ov-settings">
                    <div><dt>Deploy on push</dt><dd><?= $auto_deploy ? 'On' : 'Off' ?></dd></div>
                    <div><dt>Always on</dt><dd><?= !empty($project->always_on) ? 'On' : 'Off' ?></dd></div>
                </dl>
            </section>
        </div>
    </div>
</div>
