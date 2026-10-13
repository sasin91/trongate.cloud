<?php
/**
 * projects/{id}/services (Projects::services). Expects: $project, $services
 * (each with status, status_class, url_line, remove_question), $can_add_service,
 * $service_form, $app_count, $member_count, $is_owner.
 */
$e = fn($value) => htmlspecialchars((string) $value);
$services_href = 'projects/' . (int) $project->id . '/services';
?>
<div class="ov">
    <?php $active_tab = 'services'; require __DIR__ . '/_project_header.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>

    <div class="ov-main">
        <div class="ov-col">
            <section class="ov-card" aria-labelledby="services-h">
                <div class="ov-card-head">
                    <h2 id="services-h">Services</h2>
                    <span class="muted">images your apps can call</span>
                </div>
                <?php if ($services === []): ?>
                    <p class="ov-empty muted">No services yet. Add one and your apps get its URL on their next deploy.</p>
                <?php else: ?>
                    <ul class="member-list">
                        <?php foreach ($services as $service): ?>
                            <li>
                                <span class="member-who">
                                    <span><?= $e($service->name) ?> <span class="status-pill status-<?= $e($service->status_class) ?>"><?= $e($service->status) ?></span></span>
                                    <small class="muted mono"><?= $e($service->image) ?></small>
                                    <small class="muted"><code><?= $e($service->url_line) ?></code> &bull; <?= (int) $service->memory_mb ?> MB</small>
                                </span>
                                <span class="service-actions">
                                    <?= form_open($services_href . '/' . (int) $service->id . '/redeploy') ?>
                                        <button type="submit" class="btn-link">Redeploy</button>
                                    <?= form_close() ?>
                                    <?= form_open($services_href . '/' . (int) $service->id . '/delete', ['onsubmit' => 'return confirm(' . json_encode($service->remove_question) . ')']) ?>
                                        <button type="submit" class="btn-link">Remove</button>
                                    <?= form_close() ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <?php if ($can_add_service): ?>
            <div class="ov-col">
                <section class="ov-card" aria-labelledby="add-service-h">
                    <div class="ov-card-head">
                        <h2 id="add-service-h">Add a service</h2>
                    </div>
                    <div class="member-add">
                        <?= form_open($services_href . '/add') ?>
                            <div class="form-group">
                                <label for="service_name" class="form-label">Name</label>
                                <input type="text" name="service_name" id="service_name" class="form-input mono" placeholder="search" value="<?= $e($service_form['name']) ?>" required maxlength="40" pattern="[a-z][a-z0-9_]*">
                                <p class="form-hint">Your apps get <code>{NAME}_URL</code> on their next deploy.</p>
                            </div>
                            <div class="form-group">
                                <label for="service_image" class="form-label">Image</label>
                                <input type="text" name="service_image" id="service_image" class="form-input mono" placeholder="ghcr.io/owner/name:1.2.3" value="<?= $e($service_form['image']) ?>" maxlength="<?= Project_service::MAX_IMAGE_LENGTH ?>" required>
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
                </section>
            </div>
        <?php endif; ?>
    </div>
</div>
