<div class="content narrow">
    <div class="back-link">
        <a href="projects/<?= $project->id ?>" class="nav-link">&larr; Back to <?= htmlspecialchars($project->label) ?></a>
    </div>

    <h1>Settings</h1>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <div class="card settings-card">
        <?= form_open('projects/' . $project->id . '/settings/submit') ?>

        <div class="form-group">
            <label class="form-label">Address</label>
            <p><code><?= htmlspecialchars($urls[0]) ?></code></p>
        </div>

        <div class="form-group">
            <label for="domain" class="form-label">Custom Domain</label>
            <input type="text" name="domain" id="domain" class="form-input" placeholder="e.g. app.example.com" value="<?= htmlspecialchars($domain) ?>">
            <p class="form-hint">Create a CNAME record pointing this domain at <code><?= htmlspecialchars(PLATFORM_APPS_DOMAIN) ?></code>, then verify it below. Leave empty to remove it.</p>
        </div>

        <div class="form-group">
            <label for="persistent_paths" class="form-label">Persistent Paths</label>
            <textarea name="persistent_paths" id="persistent_paths" class="form-input mono" rows="5" placeholder="modules/blog/uploads"><?= htmlspecialchars($persistent_paths) ?></textarea>
            <p class="form-hint">One directory per line, relative to your app's root. Files written here survive deployments and are shared by all instances; everything else is reset on each deploy. Letters, numbers, <code>. _ - /</code> only.</p>
        </div>

        <div class="form-group">
            <label for="php_extensions" class="form-label">Extra PHP Extensions</label>
            <input type="text" name="php_extensions" id="php_extensions" class="form-input mono" placeholder="e.g. gd, intl" value="<?= htmlspecialchars($php_extensions) ?>">
            <p class="form-hint">Comma-separated. Installed at build time.</p>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="always_on" value="1" <?= $always_on ? 'checked' : '' ?>>
                Always on
            </label>
            <p class="form-hint">By default your app sleeps when idle and wakes on the next request, which takes a few seconds. Keep it always on to skip the wake-up.</p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">Save settings</button>
            <a href="projects/<?= $project->id ?>" class="btn-secondary">Cancel</a>
        </div>
        <?= form_close() ?>
    </div>

    <?php if ($domain_record !== null): ?>
    <h2>Custom domain</h2>
    <div class="card settings-card">
        <?php if ((string) $project->verified_domain === (string) $project->domain): ?>
        <p><code><?= htmlspecialchars((string) $project->domain) ?></code> is verified and served from the next deployment on.</p>
        <?php else: ?>
        <p>To prove you own <code><?= htmlspecialchars((string) $project->domain) ?></code>, add this DNS record, then press Verify. Until then the domain is not served.</p>
        <dl class="webhook-details">
            <dt>Type</dt>
            <dd><code>TXT</code></dd>
            <dt>Name</dt>
            <dd><code><?= htmlspecialchars($domain_record['name']) ?></code></dd>
            <dt>Value</dt>
            <dd><code><?= htmlspecialchars($domain_record['value']) ?></code></dd>
        </dl>
        <?= form_open('projects/' . $project->id . '/verify-domain') ?>
            <button type="submit" class="btn-primary">Verify</button>
        <?= form_close() ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <h2>Deploy on push</h2>
    <div class="card settings-card">
        <p>In your GitHub repository, open <strong>Settings &rarr; Webhooks &rarr; Add webhook</strong> and enter:</p>
        <dl class="webhook-details">
            <dt>Payload URL</dt>
            <dd><code><?= htmlspecialchars($webhook_url) ?></code></dd>
            <dt>Content type</dt>
            <dd><code>application/json</code></dd>
            <dt>Secret</dt>
            <dd><code><?= htmlspecialchars($webhook_secret) ?></code></dd>
            <dt>Events</dt>
            <dd>Just the <code>push</code> event</dd>
        </dl>
        <p class="form-hint">Pushes to <code><?= htmlspecialchars((string) $project->source_branch) ?></code> then deploy automatically. Keep the secret private: anyone who has it can trigger deployments.</p>
    </div>

    <h2>Delete project</h2>
    <div class="card settings-card danger-card">
        <p>Take the app offline and remove it from your dashboard. Its database and files are kept.</p>
        <div class="form-actions">
            <a href="projects/<?= $project->id ?>/delete" class="btn-danger">Delete project&hellip;</a>
        </div>
    </div>
</div>
