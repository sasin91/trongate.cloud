<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Upgrade Details
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
                <div class="detail-label">Status</div>
                <div class="detail-value"><?= out($status) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">From Version</div>
                <div class="detail-value"><?= out($from_version) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">To Version</div>
                <div class="detail-value"><?= out($to_version) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Pr Url</div>
                <div class="detail-value"><?= out($pr_url) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Summary</div>
                <div class="detail-content"><?= nl2br(out($summary)) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Error</div>
                <div class="detail-content"><?= nl2br(out($error)) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Completed At</div>
                <div class="detail-value"><?= out($completed_at) ?></div>
            </div>
        </div>
    </div>
</div>
