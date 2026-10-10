<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Sign-in Token Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
            <?= anchor('trongate_tokens-admin/delete_conf/'.$update_id, 'Delete',  array('class' => 'button danger')) ?>
        </div>
        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">User Id</div>
                <div class="detail-value"><?= out($user_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Expiry Date</div>
                <div class="detail-value"><?= out($expiry_date) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Code</div>
                <div class="detail-value"><?= out($code) ?></div>
            </div>
        </div>
    </div>
</div>
