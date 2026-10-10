<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Project Member Details
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
                <div class="detail-label">Customer Id</div>
                <div class="detail-value"><?= out($customer_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Role</div>
                <div class="detail-value"><?= out($role) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
        </div>
    </div>
</div>
