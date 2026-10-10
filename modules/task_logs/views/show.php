<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Task Log Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
        </div>
        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Task Id</div>
                <div class="detail-value"><?= out($task_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Level</div>
                <div class="detail-value"><?= out($level) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Message</div>
                <div class="detail-content"><?= nl2br(out($message)) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
        </div>
    </div>
</div>
