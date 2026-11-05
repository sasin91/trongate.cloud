<?php
/**
 * apps/{id}/git (Repository::index). Expects: $app, $urls, $live_state, $can_rollback (App_page),
 * $is_internal (the app is on trongate.cloud git), $repo_name, $source_url, $branch,
 * $can_move (a production app on GitHub) and $move_waiting (a move is queued or running).
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div class="ov">
    <?php $active_tab = 'git'; require APPPATH . 'modules/apps/views/_app_header.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <?php if ($is_internal): ?>
    <div class="card settings-card">
        <h2>trongate.cloud git</h2>
        <p>This app deploys branch <code><?= $e($branch) ?></code> of its repository on trongate.cloud, <code><?= $e($repo_name) ?></code>.</p>
        <p class="form-hint">Only trongate.cloud writes to it for now. Export to GitHub comes later.</p>
    </div>
    <?php else: ?>
    <div class="card settings-card">
        <h2>Repository</h2>
        <p>This app deploys from <a href="<?= $e($source_url) ?>" target="_blank" rel="noopener"><?= $e($source_url) ?></a>, branch <code><?= $e($branch) ?></code>.</p>
    </div>
    <?php endif; ?>

    <?php if ($can_move && $move_waiting): ?>
    <div class="card settings-card">
        <h2>Moving to trongate.cloud git</h2>
        <p>The repository is being copied. A deploy from the copy follows.</p>
    </div>
    <?php elseif ($can_move): ?>
    <div class="card settings-card">
        <h2>Move to trongate.cloud git</h2>
        <p>Every branch is copied with its history, and this app and its staging app then build from the copy. The repository on GitHub stays as it is, but pushes to it no longer deploy.</p>
        <?= form_open('apps/' . (int) $app->id . '/git/move') ?>
            <label class="checkbox-label">
                <input type="checkbox" name="confirm" value="1">
                Stop deploying from GitHub
            </label>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Move to trongate.cloud git</button>
            </div>
        <?= form_close() ?>
    </div>
    <?php endif; ?>
</div>
