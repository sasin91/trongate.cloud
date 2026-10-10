<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Captured Mail Details
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
                <div class="detail-label">Mail From</div>
                <div class="detail-value"><?= out($mail_from) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Recipients</div>
                <div class="detail-value"><?= out($recipients) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">From Header</div>
                <div class="detail-value"><?= out($from_header) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Subject</div>
                <div class="detail-value"><?= out($subject) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Bytes</div>
                <div class="detail-value"><?= out($bytes) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
        </div>
    </div>
</div>
