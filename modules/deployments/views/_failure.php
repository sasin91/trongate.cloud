<?php
/**
 * Why the deployment failed and what gets the app deploying again
 * (Config_error::to_array, see Deployments::_failure). Rendered in place on
 * page load, and swapped into #deploy-failure by the log poller when the
 * deployment fails while the page is open.
 */
?>
<div id="deploy-failure">
    <?php if ($failure !== null): ?>
        <div class="card deploy-failure" role="alert">
            <h3>Deploy failed</h3>
            <p class="deploy-failure-reason">
                <?php if ($failure['file'] !== null): ?>
                    <code>config/<?= htmlspecialchars($failure['file']) ?><?= $failure['line'] !== null ? ' line ' . (int) $failure['line'] : '' ?></code>:
                <?php endif; ?>
                <?= htmlspecialchars($failure['reason']) ?>
            </p>
            <p><?= htmlspecialchars($failure['hint']) ?></p>
            <?php if ($failure['action'] === 'fix_file' && $failure['url'] !== null): ?>
                <a href="<?= htmlspecialchars($failure['url']) ?>" class="btn-secondary" target="_blank" rel="noopener">Open on GitHub</a>
            <?php elseif ($failure['action'] === 'redeploy'): ?>
                <?= form_open('apps/' . (int) $deployment->app_id . '/deploy') ?>
                    <button type="submit" class="btn-primary">Deploy now</button>
                <?= form_close() ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
