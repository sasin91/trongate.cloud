<div class="content narrow">
    <div class="back-link">
        <a href="apps/<?= $app->id ?>" class="nav-link">&larr; Back to <?= htmlspecialchars($app->label) ?></a>
    </div>

    <h1>Settings</h1>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <div class="card settings-card">
        <?= form_open('apps/' . $app->id . '/settings/submit') ?>

        <div class="form-group">
            <label class="form-label">Address</label>
            <p><code><?= htmlspecialchars($urls[0]) ?></code></p>
        </div>

        <div class="form-group">
            <label for="domain" class="form-label">Custom Domain</label>
            <input type="text" name="domain" id="domain" class="form-input" placeholder="e.g. app.example.com" value="<?= htmlspecialchars($domain) ?>">
            <p class="form-hint">Point this domain here (see below), then verify it. Leave empty to remove it.</p>
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

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="auto_deploy" value="1" <?= $auto_deploy ? 'checked' : '' ?>>
                Deploy on push
            </label>
            <p class="form-hint">Pushes to <code><?= htmlspecialchars((string) $app->source_branch) ?></code> deploy automatically through the trongate.cloud GitHub app. Turn this off to deploy only when you press Deploy.</p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">Save settings</button>
            <a href="apps/<?= $app->id ?>" class="btn-secondary">Cancel</a>
        </div>
        <?= form_close() ?>
    </div>

    <h2 id="environment">Environment variables</h2>
    <div class="card settings-card">
        <p class="form-hint">For API keys and other secrets. Your app reads them with <code>getenv('NAME')</code>. Values are stored encrypted and never shown again; to change one, save it again under the same name. Changes take effect on the next deployment.</p>

        <?php if ($env_names !== []): ?>
        <ul class="env-list">
            <?php foreach ($env_names as $name): ?>
            <li>
                <code><?= htmlspecialchars((string) $name) ?></code>
                <?= form_open('apps/' . $app->id . '/env/delete', ['class' => 'row-actions']) ?>
                    <input type="hidden" name="env_name" value="<?= htmlspecialchars((string) $name) ?>">
                    <button type="submit" class="btn-link">Remove</button>
                <?= form_close() ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?= form_open('apps/' . $app->id . '/env/submit') ?>
        <div class="env-fields">
            <div class="form-group">
                <label for="env_name" class="form-label">Name</label>
                <input type="text" name="env_name" id="env_name" class="form-input mono" placeholder="ANTHROPIC_API_KEY" autocomplete="off" value="<?= htmlspecialchars($env_name) ?>">
            </div>
            <div class="form-group">
                <label for="env_value" class="form-label">Value</label>
                <input type="password" name="env_value" id="env_value" class="form-input mono" autocomplete="new-password">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-secondary">Save variable</button>
        </div>
        <?= form_close() ?>
    </div>

    <?php if ($domain_record !== null): ?>
    <h2>Custom domain</h2>
    <div class="card settings-card">
        <?php if ((string) $app->verified_domain === (string) $app->domain): ?>
        <?php if ($domain_secured): ?>
        <p><code><?= htmlspecialchars((string) $app->domain) ?></code> is verified, points here and has its HTTPS certificate, so it is served and is the app's base URL.</p>
        <?php elseif ($domain_routed): ?>
        <p><code><?= htmlspecialchars((string) $app->domain) ?></code> is verified and points here. It becomes the app's base URL once its HTTPS certificate is issued, usually within minutes<?= !empty($app->domain_checked_at) ? ' (checked ' . htmlspecialchars((string) $app->domain_checked_at) . ')' : '' ?>. If that takes longer, check that no CAA record for the domain excludes Let's Encrypt.</p>
        <?= form_open('apps/' . $app->id . '/verify-domain') ?>
            <button type="submit" class="btn-secondary">Check again</button>
        <?= form_close() ?>
        <?php else: ?>
        <p><code><?= htmlspecialchars((string) $app->domain) ?></code> is verified, but its DNS does not point here yet<?= !empty($app->domain_checked_at) ? ' (checked ' . htmlspecialchars((string) $app->domain_checked_at) . ')' : '' ?>. It goes live by itself once it does. Add one of these:</p>
        <dl class="webhook-details">
            <dt>CNAME</dt>
            <dd><code><?= htmlspecialchars(PLATFORM_APPS_DOMAIN) ?></code> (subdomains such as www or app)</dd>
            <?php foreach ($ingress_addresses as $address): ?>
            <dt><?= str_contains($address, ':') ? 'AAAA' : 'A' ?></dt>
            <dd><code><?= htmlspecialchars($address) ?></code></dd>
            <?php endforeach; ?>
        </dl>
        <p class="form-hint">Use A/AAAA records for a bare domain (example.com) and remove any other A/AAAA records for it.</p>
        <?= form_open('apps/' . $app->id . '/verify-domain') ?>
            <button type="submit" class="btn-secondary">Check again</button>
        <?= form_close() ?>
        <?php endif; ?>
        <?php else: ?>
        <p>To prove you own <code><?= htmlspecialchars((string) $app->domain) ?></code>, add this DNS record, then press Verify. Until then the domain is not served.</p>
        <dl class="webhook-details">
            <dt>Type</dt>
            <dd><code>TXT</code></dd>
            <dt>Name</dt>
            <dd><code><?= htmlspecialchars($domain_record['name']) ?></code></dd>
            <dt>Value</dt>
            <dd><code><?= htmlspecialchars($domain_record['value']) ?></code></dd>
        </dl>
        <?= form_open('apps/' . $app->id . '/verify-domain') ?>
            <button type="submit" class="btn-primary">Verify</button>
        <?= form_close() ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <h2>Delete app</h2>
    <div class="card settings-card danger-card">
        <p>Take the app offline and remove it from <?= htmlspecialchars((string) $app->project_name) ?>. Its database and files are kept.</p>
        <?php if ($can_delete): ?>
            <div class="form-actions">
                <a href="apps/<?= $app->id ?>/delete" class="btn-danger">Delete app&hellip;</a>
            </div>
        <?php else: ?>
            <p class="form-hint">Only the project owner can delete an app.</p>
        <?php endif; ?>
    </div>
</div>
