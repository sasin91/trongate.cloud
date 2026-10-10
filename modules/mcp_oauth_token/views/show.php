<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Assistant Token Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
        </div>
        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Grant Id</div>
                <div class="detail-value"><?= out($grant_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Kind</div>
                <div class="detail-value"><?= out($kind) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Redirect Uri</div>
                <div class="detail-value"><?= out($redirect_uri) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Expires At</div>
                <div class="detail-value"><?= out($expires_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Used At</div>
                <div class="detail-value"><?= out($used_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
        </div>
    </div>
</div>
