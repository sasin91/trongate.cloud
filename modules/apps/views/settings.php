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
            <p class="form-hint">Add your own domain on the <a href="apps/<?= $app->id ?>/domain">Domain</a> tab.</p>
        </div>

        <div class="form-group">
            <label for="persistent_paths" class="form-label">Persistent Paths</label>
            <textarea name="persistent_paths" id="persistent_paths" class="form-input mono" rows="5" placeholder="modules/blog/uploads"><?= htmlspecialchars($persistent_paths) ?></textarea>
            <p class="form-hint">One directory per line, relative to your app's root. Files written here survive deployments and are shared by all instances; everything else is reset on each deploy. Letters, numbers, <code>. _ - /</code> only.</p>
        </div>

        <div class="form-group">
            <label for="trongate_env" class="form-label">Environment</label>
            <input type="text" name="trongate_env" id="trongate_env" class="form-input mono" list="trongate_env_options" value="<?= htmlspecialchars($trongate_env) ?>">
            <datalist id="trongate_env_options">
                <?php foreach (Trongate_config::ENV_SUGGESTIONS as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>">
                <?php endforeach; ?>
            </datalist>
            <p class="form-hint">Your app's <code>ENV</code> constant in <code>config/config.php</code>. In <code>dev</code>, Trongate shows error details; use <code>prod</code> to hide them from visitors.</p>
        </div>

        <div class="form-group">
            <label for="health_path" class="form-label">Health Check Path</label>
            <input type="text" name="health_path" id="health_path" class="form-input mono" placeholder="/" value="<?= htmlspecialchars($health_path) ?>">
            <p class="form-hint">Each deploy goes live only once this path answers with a 2xx or 3xx. The home page by default; an API whose home page is a 404 can use a health endpoint such as <code>/health</code>.</p>
        </div>

        <div class="form-group">
            <label for="php_extensions" class="form-label">Extra PHP Extensions</label>
            <input type="text" name="php_extensions" id="php_extensions" class="form-input mono" placeholder="e.g. gd, intl" value="<?= htmlspecialchars($php_extensions) ?>">
            <p class="form-hint">Comma-separated. Installed at build time.</p>
        </div>

        <div class="form-group" id="processes">
            <label class="form-label">Processes</label>
            <input type="hidden" name="processes" value="1">
            <table class="process-table">
                <thead>
                    <tr><th>Name</th><th>Command</th><th>Replicas</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_merge($processes, [['name' => '', 'command' => '', 'replicas' => 1]]) as $process): ?>
                    <tr>
                        <td><input type="text" name="process_name[]" class="form-input mono" placeholder="worker" aria-label="Name" value="<?= htmlspecialchars((string) $process['name']) ?>"></td>
                        <td><input type="text" name="process_command[]" class="form-input mono" placeholder="php bin/queue.php work --time-limit=3600" aria-label="Command" value="<?= htmlspecialchars((string) $process['command']) ?>"></td>
                        <td><input type="number" name="process_replicas[]" class="form-input" min="1" max="<?= Manifests::MAX_PROCESS_REPLICAS ?>" aria-label="Replicas" value="<?= (int) $process['replicas'] ?>"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="form-hint">Commands that run next to your app, from the same code, settings and database, for work that shouldn't wait on a web request, such as a queue worker. They never sleep, and restart when they exit. Replicas run side by side, so the work they share must be safe to run concurrently. Clear a row's name and command to remove it; save to add another row. Lowercase letters, numbers and <code>-</code> in names.</p>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="always_on" value="1" <?= $always_on ? 'checked' : '' ?>>
                Always on
            </label>
            <p class="form-hint">By default your app sleeps when idle and wakes on the next request, which takes a few seconds. Keep it always on to skip the wake-up.</p>
        </div>

        <div class="form-group">
            <label for="source_branch" class="form-label">Branch</label>
            <input type="text" name="source_branch" id="source_branch" class="form-input mono" autocomplete="off" value="<?= htmlspecialchars($source_branch) ?>">
            <p class="form-hint">Where this app deploys from.</p>
        </div>

        <div class="form-group">
            <?php if (!empty($app->source_public)): ?>
            <p class="form-label">Deploy on push</p>
            <p class="form-hint">This app deploys from a public link, so GitHub does not tell us about pushes. Press Deploy to put the branch's latest commit live.</p>
            <?php else: ?>
            <label class="checkbox-label">
                <input type="checkbox" name="auto_deploy" value="1" <?= $auto_deploy ? 'checked' : '' ?>>
                Deploy on push
            </label>
            <p class="form-hint">Pushes to the branch deploy automatically through the trongate.cloud GitHub app. Turn this off to deploy only when you press Deploy.</p>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="mail_capture" value="1" <?= $mail_capture ? 'checked' : '' ?>>
                Capture mail
            </label>
            <p class="form-hint">Mail your app sends with Trongate's email module goes to its <a href="apps/<?= $app->id ?>/mail">Mail</a> tab instead of being delivered; we set the SMTP settings in <code>config/trongate_email.php</code> for you. Turn this off to send real mail with your own SMTP settings in that file (port 587 or 465).</p>
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
