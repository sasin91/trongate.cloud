<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Project Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
        </div>
        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Name</div>
                <div class="detail-value"><?= out($name) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Namespace</div>
                <div class="detail-value"><?= out($namespace) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Owner Id</div>
                <div class="detail-value"><?= out($owner_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Deleted At</div>
                <div class="detail-value"><?= out($deleted_at) ?></div>
            </div>
        </div>
    </div>
</div>
