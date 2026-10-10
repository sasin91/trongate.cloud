<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Database Tab Entry Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
        </div>
        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">App Id</div>
                <div class="detail-value"><?= out($app_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Customer Id</div>
                <div class="detail-value"><?= out($customer_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Action</div>
                <div class="detail-value"><?= out($action) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Statement</div>
                <div class="detail-content"><?= nl2br(out($statement)) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Allow Changes</div>
                <div class="detail-value"><?= out($allow_changes) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Affected</div>
                <div class="detail-value"><?= out($affected) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Error</div>
                <div class="detail-value"><?= out($error) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
        </div>
    </div>
</div>
