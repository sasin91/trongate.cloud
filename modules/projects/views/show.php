<?php
$e = fn($value) => htmlspecialchars((string) $value);
$app_count = count($apps);
$member_count = count($members);
$new_app_href = 'projects/' . (int) $project->id . '/apps/new';
?>
<div class="ov">
    <div class="ov-head">
        <div class="ov-title">
            <div class="ov-title-row">
                <h1><?= $e($project->name) ?></h1>
            </div>
            <div class="ov-meta">
                <span><?= $app_count ?> <?= $app_count === 1 ? 'app' : 'apps' ?></span>
                <span><?= $member_count ?> <?= $member_count === 1 ? 'member' : 'members' ?></span>
                <span><?= $is_owner ? 'You own this project' : 'You are a member' ?></span>
            </div>
        </div>
        <div class="ov-actions">
            <a href="<?= $e($new_app_href) ?>" class="btn-primary">New app</a>
        </div>
    </div>

    <nav class="ov-tabs" aria-label="Project sections">
        <a href="projects/<?= (int) $project->id ?>" aria-current="page">Apps</a>
        <a href="projects/<?= (int) $project->id ?>#services">Services</a>
        <a href="projects/<?= (int) $project->id ?>#members">Members</a>
        <?php if ($is_owner): ?>
            <a href="projects/<?= (int) $project->id ?>/settings">Settings</a>
        <?php endif; ?>
    </nav>

    <?= flashdata('<p class="flash">', '</p>') ?>

    <div class="ov-main">
        <div class="ov-col">
            <?php require __DIR__ . '/_app_cards.php'; ?>
        </div>

        <div class="ov-col">
            <section class="ov-card" id="services" aria-labelledby="services-h">
                <div class="ov-card-head">
                    <h2 id="services-h">Services</h2>
                    <span class="muted">images your apps can call</span>
                </div>
                <?php if ($services !== []): ?>
                    <ul class="member-list">
                        <?php foreach ($services as $service): ?>
                            <?php $status = (string) ($service->status ?? 'queued'); ?>
                            <li>
                                <span class="member-who">
                                    <span><?= $e($service->name) ?> <span class="status-pill status-<?= $e($status === 'running' ? 'success' : ($status === 'failed' ? 'failed' : 'pending')) ?>"><?= $e($status) ?></span></span>
                                    <small class="muted mono"><?= $e($service->image) ?></small>
                                    <small class="muted"><code><?= $e(Project_service::url_variable($service->name)) ?>=<?= $e(Project_service::url((int) $service->id, (int) $service->port)) ?></code> &bull; <?= (int) $service->memory_mb ?> MB</small>
                                </span>
                                <span class="service-actions">
                                    <?= form_open('projects/' . (int) $project->id . '/services/' . (int) $service->id . '/redeploy') ?>
                                        <button type="submit" class="btn-link">Redeploy</button>
                                    <?= form_close() ?>
                                    <?= form_open('projects/' . (int) $project->id . '/services/' . (int) $service->id . '/delete', ['onsubmit' => 'return confirm(' . json_encode('Remove ' . $service->name . '? Apps calling it will get errors until you redeploy them without it.') . ')']) ?>
                                        <button type="submit" class="btn-link">Remove</button>
                                    <?= form_close() ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (count($services) < Project_service::MAX_PER_PROJECT): ?>
                    <div class="member-add">
                        <?= form_open('projects/' . (int) $project->id . '/services/add') ?>
                            <div class="form-group">
                                <label for="service_name" class="form-label">Name</label>
                                <input type="text" name="service_name" id="service_name" class="form-input mono" placeholder="laya" value="<?= $e($service_form['name']) ?>" required maxlength="40" pattern="[a-z][a-z0-9_]*">
                                <p class="form-hint">Your apps get <code>{NAME}_URL</code> on their next deploy. Leave the rest empty for a ready-made one: <?= implode(', ', array_map(fn(string $preset) => '<code>' . $e($preset) . '</code>', array_keys(Project_service::presets()))) ?>.</p>
                            </div>
                            <div class="form-group">
                                <label for="service_image" class="form-label">Image</label>
                                <input type="text" name="service_image" id="service_image" class="form-input mono" placeholder="ghcr.io/owner/name:1.2.3" value="<?= $e(post('service_image', true)) ?>" maxlength="<?= Project_service::MAX_IMAGE_LENGTH ?>">
                            </div>
                            <div class="form-group">
                                <label for="service_port" class="form-label">Port</label>
                                <input type="number" name="service_port" id="service_port" class="form-input mono" placeholder="<?= Project_service::DEFAULT_PORT ?>" min="1024" max="65535" value="<?= $e(post('service_port', true)) ?>">
                                <p class="form-hint">Where the container listens. It also gets <code>PORT</code> set to this.</p>
                            </div>
                            <div class="form-group">
                                <label for="service_memory_mb" class="form-label">Memory (MB)</label>
                                <input type="number" name="service_memory_mb" id="service_memory_mb" class="form-input mono" placeholder="<?= Project_service::DEFAULT_MEMORY_MB ?>" min="<?= Project_service::MIN_MEMORY_MB ?>" max="<?= Project_service::MAX_MEMORY_MB ?>" value="<?= $e(post('service_memory_mb', true)) ?>">
                            </div>
                            <div class="form-group">
                                <label for="service_env" class="form-label">Environment variables</label>
                                <textarea name="service_env" id="service_env" class="form-input mono" rows="3" placeholder="KEY=value, one per line"></textarea>
                                <p class="form-hint">Write-only. Runs always on, without root, reachable only from this project's apps.</p>
                            </div>
                            <button type="submit" class="btn-secondary">Add service</button>
                        <?= form_close() ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="ov-card" id="members" aria-labelledby="members-h">
                <div class="ov-card-head">
                    <h2 id="members-h">Members</h2>
                    <span class="muted">see and deploy every app</span>
                </div>
                <ul class="member-list">
                    <?php foreach ($members as $member): ?>
                        <li>
                            <span class="member-who">
                                <span><?= $e($member->email) ?><?= (int) $member->customer_id === $customer_id ? ' (you)' : '' ?></span>
                                <small class="muted"><?= $e($member->role) ?></small>
                            </span>
                            <?php if ($is_owner && $member->role !== 'owner'): ?>
                                <?= form_open('projects/' . (int) $project->id . '/members/' . (int) $member->customer_id . '/remove', ['onsubmit' => 'return confirm(' . json_encode('Remove ' . $member->email . ' from this project?') . ')']) ?>
                                    <button type="submit" class="btn-link">Remove</button>
                                <?= form_close() ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($is_owner): ?>
                    <div class="member-add">
                        <?= validation_errors('<p class="form-hint">', '</p>') ?>
                        <?= form_open('projects/' . (int) $project->id . '/members/add') ?>
                            <div class="form-group">
                                <label for="email" class="form-label">Add a member by email</label>
                                <input type="email" name="email" id="email" class="form-input" placeholder="colleague@example.com" value="<?= $e($email) ?>" required maxlength="255">
                                <p class="form-hint">They need a trongate.cloud account already. Members can deploy, roll back and change app settings; only you can delete.</p>
                            </div>
                            <button type="submit" class="btn-secondary">Add member</button>
                        <?= form_close() ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
