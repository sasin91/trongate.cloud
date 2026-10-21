<?php
/**
 * Loaded via MX into the "Sign in as customer" modal on customer-admin/show/{id}.
 */
echo form_open('impersonation/start/' . $customer_id, ['id' => 'impersonate-form']);
?>
    <p>You will use the panel as <strong><?= out($customer_label) ?></strong> for up to <?= $minutes ?> minutes. Everything you do there is done as them.</p>
    <p>A banner on every panel page takes you back here. Starting and stopping is written to the platform's logs.</p>
<?= form_close() ?>
