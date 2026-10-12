<?php
/**
 * App overview: header, environment map, deployments and side cards.
 * Shared by apps/show and the landing page's product shot.
 *
 * Expects: $app, $urls, $current (deployment|false), $deployments, $can_rollback,
 * $live_state (array|null), $database_name, and optionally $processes and
 * $process_states (see _processes.php), $services (the project's services,
 * Platform_actions::project_services), $environment, $environments, $go_live_hint
 * and $can_add_staging (App_page::_environment_data), $starting_sql_failure (Apps::_starting_sql_failure)
 * and $preview (true: no forms, no polling).
 */
$preview = $preview ?? false;
$processes = $processes ?? [];
$process_states = $process_states ?? null;
$services = $services ?? [];
$starting_sql_failure = $starting_sql_failure ?? null;
$e = fn($value) => htmlspecialchars((string) $value);
$short_sha = fn(?string $sha) => $sha ? substr($sha, 0, 7) : '';
$is_finished = fn(object $d) => in_array($d->status, ['success', 'failed', 'rolled_back'], true);
$repository = preg_replace('#^https?://(www\.)?github\.com/#', '', (string) $app->source_url);
$auto_deploy = !isset($app->auto_deploy) || !empty($app->auto_deploy);

// The custom domain's progress: verify ownership, point DNS here, get a certificate
$domain = (string) ($app->domain ?? '');
$domain_state = null;
if ($domain !== '') {
    if ((string) ($app->verified_domain ?? '') !== $domain) {
        $domain_state = ['label' => 'Needs verifying', 'class' => 'warn', 'hint' => 'Add the TXT record from the Domain tab to prove you own it.'];
    } elseif (empty($app->domain_pointed_at)) {
        $domain_state = ['label' => 'Waiting for DNS', 'class' => 'warn', 'hint' => 'Point its DNS here with the records on the Domain tab. It goes live by itself once they resolve.'];
    } elseif (empty($app->domain_secured_at)) {
        $domain_state = ['label' => 'Issuing certificate', 'class' => 'warn', 'hint' => 'It points here. HTTPS switches on once the certificate is issued.'];
    } else {
        $domain_state = ['label' => 'HTTPS', 'class' => 'ok', 'hint' => ''];
    }
}
// A production app whose staging app took {slug}.PLATFORM_APPS_DOMAIN answers only on its domain
$serves_slug_host = Manifests::serves_slug_host((array) $app);
$domain_count = ($serves_slug_host ? 1 : 0) + ($domain === '' ? 0 : 1);
// {slug}.PLATFORM_APPS_DOMAIN is for trying things out; adding a domain is going live
$environment ??= $domain === '' ? 'staging' : 'production';
$go_live_hint ??= $domain === '';
$can_add_staging ??= false;
$platform_host = $app->slug . '.' . PLATFORM_APPS_DOMAIN;
?>
<div class="ov">
    <?php $active_tab = 'overview'; require __DIR__ . '/_app_header.php'; ?>

    <?php if (!$preview): ?>
        <?= flashdata('<p class="flash">', '</p>') ?>
    <?php endif; ?>

    <?php if ($starting_sql_failure !== null): ?>
        <div class="ov-starting-sql" role="alert">
            <p><strong>The starting SQL did not finish.</strong> <?= $e($starting_sql_failure['message']) ?></p>
            <details>
                <summary>The SQL that did not run</summary>
                <pre><?= $e($starting_sql_failure['remaining_sql']) ?></pre>
            </details>
            <p class="muted">Fix it and run it from the <a href="apps/<?= (int) $app->id ?>/database?view=sql">Database tab</a>.</p>
        </div>
    <?php endif; ?>

    <div class="ov-main">
        <div class="ov-col">
            <section class="ov-card" aria-labelledby="env-h">
                <div class="ov-card-head">
                    <h2 id="env-h">Environment</h2>
                    <?php if (!$preview && $can_add_staging): ?>
                        <?= form_open('apps/' . $app->id . '/staging/submit', ['class' => 'ov-head-form']) ?>
                            <button type="submit" class="btn-link">Add staging</button>
                        <?= form_close() ?>
                    <?php else: ?>
                        <span class="muted"><?= $environment ?></span>
                    <?php endif; ?>
                </div>
                <div class="ov-canvas">
                    <div class="ov-env">
                        <div class="ov-node">
                            <span class="ov-node-kind">Source</span>
                            <strong><?= $e($repository) ?></strong>
                            <span class="muted">Branch <?= $e($app->source_branch) ?><?= $auto_deploy ? ' &middot; push to deploy' : '' ?></span>
                        </div>
                        <div class="ov-link" aria-hidden="true"></div>
                        <div class="ov-node ov-node-app">
                            <span class="ov-node-kind">App</span>
                            <strong><?= $e($app->label) ?></strong>
                            <div class="ov-chips">
                                <span><?= !empty($app->always_on) ? 'Always on' : 'Sleeps when idle' ?></span>
                                <span><?= $e(['trongate_v2' => 'Trongate v2', 'trongate_v1' => 'Trongate v1'][$app->type] ?? $app->type) ?></span>
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
                        <div class="ov-attached">
                        <div class="ov-node ov-node-db">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
                            <div>
                                <span class="ov-node-kind">Database</span>
                                <strong class="ov-mono"><?= $e($database_name) ?></strong>
                                <span class="muted">MariaDB<?php if (!$preview): ?> &middot; <a href="apps/<?= (int) $app->id ?>/database">Open</a><?php endif; ?></span>
                            </div>
                        </div>
                        <?php if ($processes !== [] || !empty($process_states)): ?>
                            <div class="ov-processes"<?php if (!$preview && $process_states !== null): ?> mx-get="apps/<?= (int) $app->id ?>/processes" mx-trigger="every 5s"<?php endif; ?>>
                                <?php require __DIR__ . '/_processes.php'; ?>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($services as $service): ?>
                            <?php $status = (string) ($service->status ?? 'queued'); ?>
                            <div class="ov-node ov-node-db">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/></svg>
                                <div>
                                    <span class="ov-node-kind">Service</span>
                                    <strong class="ov-mono"><?= $e($service->name) ?></strong>
                                    <span class="muted"><?= $e(Project_service::url_variable((string) $service->name)) ?> &middot; <span class="<?= $status === 'failed' ? 'ov-warn-text' : '' ?>"><?= $e($status) ?></span><?php if (!$preview): ?> &middot; <a href="projects/<?= (int) $app->project_id ?>#services">Manage</a><?php endif; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <?php if (!$preview && $services === []): ?>
                            <a class="ov-add-service" href="projects/<?= (int) $app->project_id ?>#services">Add a service</a>
                        <?php endif; ?>
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
                                                <?= form_open('apps/' . $app->id . '/rollback', ['onsubmit' => "return confirm('Roll back to deployment #" . (int) $deployment->id . "?')"]) ?>
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
                        <?php if ($current->commit_sha && str_starts_with((string) $app->source_url, 'https://github.com/')): ?>
                            <a href="<?= $e(rtrim((string) $app->source_url, '/') . '/commit/' . $current->commit_sha) ?>" target="_blank" rel="noopener">View on GitHub</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </section>

            <?php if (($log_rates ?? null) !== null): ?>
                <?php require __DIR__ . '/_log_rates.php'; ?>
            <?php endif; ?>

            <section class="ov-card" aria-labelledby="dom-h">
                <div class="ov-card-head">
                    <h2 id="dom-h">Domains</h2>
                    <a href="apps/<?= (int) $app->id ?>/domain"><?= $domain === '' ? 'Add domain' : 'Manage' ?></a>
                </div>
                <?php if ($serves_slug_host): ?>
                    <div class="ov-domain">
                        <span class="ov-mono"><?= $e($platform_host) ?></span>
                        <span class="ov-badge ok">HTTPS</span>
                    </div>
                <?php endif; ?>
                <?php if ($domain_state === null && $go_live_hint): ?>
                    <p class="ov-domain-hint muted">Your staging address. Add your own domain when you're ready to go live.</p>
                <?php elseif ($domain_state !== null): ?>
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
                    <a href="apps/<?= (int) $app->id ?>/settings">Edit</a>
                </div>
                <dl class="ov-settings">
                    <div><dt>Deploy on push</dt><dd><?= $auto_deploy ? 'On' : 'Off' ?></dd></div>
                    <div><dt>Always on</dt><dd><?= !empty($app->always_on) ? 'On' : 'Off' ?></dd></div>
                    <div><dt>Processes</dt><dd><?php if ($processes === []): ?>None<?php else: ?><?php foreach ($processes as $process): ?><code><?= $e($process['name']) ?><?= $process['replicas'] > 1 ? ' &times;' . (int) $process['replicas'] : '' ?></code> <?php endforeach; ?><?php endif; ?></dd></div>
                </dl>
            </section>
        </div>
    </div>
</div>
