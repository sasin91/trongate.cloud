<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Password Reset Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
            <?= anchor('password_resets/delete_conf/'.$update_id, 'Delete',  array('class' => 'button danger')) ?>
        </div>
        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Target Table</div>
                <div class="detail-value"><?= out($target_table) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Identifier</div>
                <div class="detail-value"><?= out($identifier) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Expiry Date</div>
                <div class="detail-value"><?= out($expiry_date) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Used</div>
                <div class="detail-value"><?= out($used) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
        </div>
    </div>
</div>
