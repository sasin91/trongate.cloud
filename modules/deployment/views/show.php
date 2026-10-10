<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Deployment Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
        </div>
        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Project Id</div>
                <div class="detail-value"><?= out($project_id) ?></div>
            </div>
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
                <div class="detail-label">Source Url</div>
                <div class="detail-value"><?= out($source_url) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Commit Sha</div>
                <div class="detail-value"><?= out($commit_sha) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Image</div>
                <div class="detail-value"><?= out($image) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Failure</div>
                <div class="detail-content"><?= nl2br(out($failure)) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Config Version</div>
                <div class="detail-value"><?= out($config_version) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Trigger Type</div>
                <div class="detail-value"><?= out($trigger_type) ?></div>
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
