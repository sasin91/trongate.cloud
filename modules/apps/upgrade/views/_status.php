<?php
/**
 * The latest check and the button: on the page (index.php) and polled from
 * Upgrade::status while a check runs. Once it is done, #upgrade-poller-stop
 * tells the page's poller to remove itself.
 *
 * Expects: $app, $upgrade (App_upgrades::latest_upgrade), $checking.
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div id="upgrade-status">
    <?php if ($upgrade !== false): ?>
    <p>
        <?php if ($checking): ?>
        Checking for a newer Trongate&hellip;
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
</div>
<?php if (!$checking): ?><span id="upgrade-poller-stop" hidden></span><?php endif; ?>
