<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Database Backup Details
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
                <div class="detail-label">Log Id</div>
                <div class="detail-value"><?= out($log_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Summary</div>
                <div class="detail-value"><?= out($summary) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Rows</div>
                <div class="detail-value"><?= out($rows) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Bytes</div>
                <div class="detail-value"><?= out($bytes) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Stored Bytes</div>
                <div class="detail-value"><?= out($stored_bytes) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Expires At</div>
                <div class="detail-value"><?= out($expires_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Restored At</div>
                <div class="detail-value"><?= out($restored_at) ?></div>
            </div>
        </div>
    </div>
</div>
