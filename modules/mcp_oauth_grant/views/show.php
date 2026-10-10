<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Assistant Connection Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
        </div>
        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Client Id</div>
                <div class="detail-value"><?= out($client_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Customer Id</div>
                <div class="detail-value"><?= out($customer_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Scope</div>
                <div class="detail-value"><?= out($scope) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Last Used At</div>
                <div class="detail-value"><?= out($last_used_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Revoked At</div>
                <div class="detail-value"><?= out($revoked_at) ?></div>
            </div>
        </div>
    </div>
</div>
