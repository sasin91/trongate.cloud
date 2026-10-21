<div class="impersonation-banner" role="status">
    <span>Signed in as <strong><?= out($impersonation['customer_label']) ?></strong> from tg-admin (<?= out($impersonation['admin']) ?>) until <?= out($impersonation['until']) ?>.</span>
    <?= form_open('impersonate/stop') ?>
        <button type="submit">Stop and go back to tg-admin</button>
    <?= form_close() ?>
</div>
