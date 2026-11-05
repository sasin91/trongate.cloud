<?php
/**
 * apps/{id}/git (Repository::index). Expects: $app, $urls, $live_state, $can_rollback (App_page),
 * $is_internal (the app is on trongate.cloud git), $clone_url, $branch, $tokens (Git_tokens::app_git_tokens)
 * and $new_token (a token made by this request, shown once, or null).
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div class="ov">
    <?php $active_tab = 'git'; require APPPATH . 'modules/apps/views/_app_header.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <?php if (!$is_internal): ?>
    <div class="card settings-card">
        <h2>Repository</h2>
        <p>This app deploys from <a href="<?= $e($clone_url) ?>" rel="noopener"><?= $e($clone_url) ?></a>, branch <code><?= $e($branch) ?></code>.</p>
    </div>
    <?php else: ?>
    <div class="card settings-card">
        <h2>trongate.cloud git</h2>
        <p>Pushing to <code><?= $e($branch) ?></code> deploys the app, unless Deploy on push is off in Settings.</p>
        <div class="form-group">
            <label for="clone-url" class="form-label">Clone address</label>
            <input type="text" id="clone-url" class="form-input mono" readonly value="<?= $e($clone_url) ?>">
        </div>
        <p class="form-hint">Use an access token as the password; any user name works.</p>
<pre class="mono">git remote add trongate <?= $e($clone_url) ?>

git push trongate <?= $e($branch) ?></pre>
    </div>

    <?php if ($new_token !== null): ?>
    <div class="card settings-card">
        <h2>Your new access token</h2>
        <p>Copy it now: it is not shown again.</p>
        <input type="text" class="form-input mono" readonly value="<?= $e($new_token) ?>" aria-label="New access token">
    </div>
    <?php endif; ?>

    <div class="card settings-card">
        <h2>Access tokens</h2>
        <p class="form-hint">A token opens this repository for clone and push. It stops working when you revoke it, or when the member who made it leaves the project.</p>

        <?php if ($tokens !== []): ?>
        <table class="process-table">
            <thead>
                <tr><th>Name</th><th>Ends in</th><th>Made by</th><th>Last used</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($tokens as $token): ?>
                <tr>
                    <td><?= $e($token->label !== '' ? $token->label : 'Unnamed') ?></td>
                    <td><code>&hellip;<?= $e($token->last_four) ?></code></td>
                    <td><?= $e($token->customer_email) ?></td>
                    <td><?= $e($token->last_used_at ?? 'never') ?></td>
                    <td>
                        <?= form_open('apps/' . (int) $app->id . '/git/tokens/revoke') ?>
                            <input type="hidden" name="token_id" value="<?= (int) $token->id ?>">
                            <button type="submit" class="btn-link">Revoke</button>
                        <?= form_close() ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?= form_open('apps/' . (int) $app->id . '/git/tokens/submit') ?>
            <div class="form-group">
                <label for="label" class="form-label">Token name</label>
                <input type="text" name="label" id="label" class="form-input" maxlength="60" placeholder="Laptop" autocomplete="off">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Make a token</button>
            </div>
        <?= form_close() ?>
    </div>
    <?php endif; ?>
</div>
