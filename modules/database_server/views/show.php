<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        Database Server Details
    </div>
    <div class="card-body">
        <div class="text-right mb-3">
            <?= anchor($back_url, 'Back', array('class' => 'button alt')) ?>
        </div>
        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Label</div>
                <div class="detail-value"><?= out($label) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Host</div>
                <div class="detail-value"><?= out($host) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Port</div>
                <div class="detail-value"><?= out($port) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Operator Name</div>
                <div class="detail-value"><?= out($operator_name) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Operator Namespace</div>
                <div class="detail-value"><?= out($operator_namespace) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Capacity</div>
                <div class="detail-value"><?= out($capacity) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Active</div>
                <div class="detail-value"><?= out($active) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
        </div>
    </div>
</div>
