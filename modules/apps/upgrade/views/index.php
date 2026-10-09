<?php
/**
 * apps/{id}/upgrade (Upgrade::index). Expects: $app, $urls, $live_state, $can_rollback,
 * $upgrade (App_upgrades::latest_upgrade), $checking (a check is queued or running).
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div class="ov">
    <?php $active_tab = 'upgrade'; require APPPATH . 'modules/apps/views/_app_header.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>

    <div class="card settings-card">
        <h2>Trongate version</h2>

        <?php if ($app->type !== 'trongate_v2'): ?>
        <p class="form-hint">Upgrades are for Trongate v2 apps. Moving from v1 to v2 changes your own code, so it is not covered.</p>
        <?php else: ?>
        <p class="form-hint">Opens a pull request on your repository that moves your app to the newest Trongate release. Framework files you changed are merged with the newer ones, and your config values stay as they are. Nothing deploys until you merge it.</p>

        <?php if ($upgrade !== false): ?>
        <p>
            <?php if ($checking): ?>
            Checking for a newer Trongate&hellip; Reload this page in a minute.
            <?php elseif ($upgrade->status === 'current'): ?>
            On the newest release, <code><?= $e($upgrade->to_version) ?></code>.
            <?php elseif ($upgrade->status === 'opened'): ?>
            Upgrade from <code><?= $e($upgrade->from_version) ?></code> to <code><?= $e($upgrade->to_version) ?></code>:
            <a href="<?= $e($upgrade->pr_url) ?>" target="_blank" rel="noopener">review the pull request</a>.
            <?php if (!empty($upgrade->summary['conflicts'])): ?>
            <?= (int) $upgrade->summary['conflicts'] ?> <?= (int) $upgrade->summary['conflicts'] === 1 ? 'file needs' : 'files need' ?> your call before merging.
            <?php endif; ?>
            <?php if (!empty($upgrade->summary['sql'])): ?>
            It adds SQL to import in the <a href="apps/<?= (int) $app->id ?>/database">Database tab</a>.
            <?php endif; ?>
            <?php else: ?>
            The last check failed: <?= $e($upgrade->error) ?>
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <?php if (!$checking): ?>
        <?= form_open('apps/' . $app->id . '/upgrade/submit') ?>
            <div class="form-actions">
                <button type="submit" class="btn-secondary">Check for an upgrade</button>
            </div>
        <?= form_close() ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
