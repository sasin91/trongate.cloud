<div class="content narrow">
    <?= flashdata('<p class="flash">', '</p>') ?>

    <h2>Claude</h2>
    <p class="claude-lead">Connect Claude to trongate.cloud and ask it to deploy, read logs, change settings and environment variables, and look at or fix your apps' databases. It works as you, in your projects, and asks before it changes anything.</p>

    <div class="card settings-card">
        <h3>In Claude on the web, desktop or phone</h3>
        <ol class="claude-steps">
            <li>Open <strong>Settings</strong>, then <strong>Connectors</strong>, and choose <strong>Add custom connector</strong>.</li>
            <li>Name it <code>trongate.cloud</code> and paste this URL:
                <code class="claude-url mono"><?= htmlspecialchars($server_url) ?></code>
            </li>
            <li>Press <strong>Connect</strong>, sign in here and choose <strong>Allow</strong>.</li>
        </ol>
    </div>

    <div class="card settings-card">
        <h3>In Claude Code</h3>
        <pre class="claude-command mono">claude mcp add --transport http trongate <?= htmlspecialchars($server_url) ?></pre>
        <p class="form-hint">Then run <code>/mcp</code> in Claude Code, pick <code>trongate</code> and sign in.</p>
    </div>

    <h2>Connected</h2>
    <div class="card settings-card">
        <?php if ($connections === []): ?>
            <p class="form-hint">Nothing is connected yet.</p>
        <?php else: ?>
            <ul class="env-list">
                <?php foreach ($connections as $connection): ?>
                <li>
                    <span>
                        <strong><?= htmlspecialchars((string) $connection->client_name) ?></strong>
                        <span class="claude-meta">connected <?= htmlspecialchars(date('j M Y', strtotime((string) $connection->created_at))) ?><?= $connection->last_used_at !== null ? ', last used ' . htmlspecialchars(date('j M Y H:i', strtotime((string) $connection->last_used_at))) : '' ?></span>
                    </span>
                    <?= form_open('integrations/claude/' . (int) $connection->id . '/disconnect', ['class' => 'row-actions']) ?>
                        <button type="submit" class="btn-link">Disconnect</button>
                    <?= form_close() ?>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="form-hint">Database changes Claude makes are listed under Recent activity on the app's Database tab, like your own. Claude cannot delete apps or projects or manage members.</p>
    </div>
</div>
