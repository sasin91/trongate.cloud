<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Secret Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
        </div>
        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Module</div>
                <div class="detail-value"><?= out($module) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Module Id</div>
                <div class="detail-value"><?= out($module_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Service</div>
                <div class="detail-value"><?= out($service) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Updated At</div>
                <div class="detail-value"><?= out($updated_at) ?></div>
            </div>
        </div>
    </div>
</div>
