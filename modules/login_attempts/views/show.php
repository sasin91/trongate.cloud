<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Failed Sign-in Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
            <?= anchor('login_attempts/delete_conf/'.$update_id, 'Delete',  array('class' => 'button danger')) ?>
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
                <div class="detail-label">Ip Address</div>
                <div class="detail-value"><?= out($ip_address) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Attempted At</div>
                <div class="detail-value"><?= out($attempted_at) ?></div>
            </div>
        </div>
    </div>
</div>
