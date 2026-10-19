<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Customer Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
        </div>
        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Trongate User Id</div>
                <div class="detail-value"><?= out($trongate_user_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Email</div>
                <div class="detail-value"><?= out($email) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Github User Id</div>
                <div class="detail-value"><?= out($github_user_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Github Login</div>
                <div class="detail-value"><?= out($github_login) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Active</div>
                <div class="detail-value"><?= out($active) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Onboarded At</div>
                <div class="detail-value"><?= out($onboarded_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
        </div>
    </div>
</div>
<?php foreach ($related_panels as $panel): ?>
    <?= Modules::run('related_records/draw_panel', $panel) ?>
<?php endforeach; ?>
