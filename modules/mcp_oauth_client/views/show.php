<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Assistant Client Details
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
                <div class="detail-label">Provider</div>
                <div class="detail-value"><?= out($provider) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Client Name</div>
                <div class="detail-value"><?= out($client_name) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Redirect Uris</div>
                <div class="detail-content"><?= nl2br(out($redirect_uris)) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
        </div>
    </div>
</div>
