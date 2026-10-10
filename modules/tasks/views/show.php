<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Task Details
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
                <div class="detail-label">Deployment Id</div>
                <div class="detail-value"><?= out($deployment_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Script</div>
                <div class="detail-value"><?= out($script) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Payload</div>
                <div class="detail-content"><?= nl2br(out($payload)) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Started At</div>
                <div class="detail-value"><?= out($started_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Completed At</div>
                <div class="detail-value"><?= out($completed_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Failed At</div>
                <div class="detail-value"><?= out($failed_at) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Error Message</div>
                <div class="detail-content"><?= nl2br(out($error_message)) ?></div>
            </div>
        </div>
    </div>
</div>
