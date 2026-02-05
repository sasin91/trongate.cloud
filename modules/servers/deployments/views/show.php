<h1>Deployment Details</h1>

<div class="card">
    <div class="card-body">
        <h3>Deployment #<?= $deployment->id ?></h3>
        <p><strong>Status:</strong> <span class="badge <?= $deployment->status ?>"><?= $deployment->status ?></span></p>
        <p><strong>Source:</strong> <?= $deployment->source_type ?> (<?= $deployment->source_url ?>)</p>
        <p><strong>Created At:</strong> <?= $deployment->created_at ?></p>
        
        <?php 
        $metadata = json_decode($deployment->metadata ?? '{}');
        if ($metadata): ?>
            <div class="metadata">
                <?php foreach ($metadata as $key => $value): ?>
                    <p><strong><?= ucfirst($key) ?>:</strong> <?= $value ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="<?= BASE_URL ?>server-deployments_module/js/deployment_logs.js" type="module"></script>
<deployment-logs url="<?= BASE_URL ?>server-deployments/stream_logs/<?= $deployment->id ?>"></deployment-logs>

<style>
.badge {
    padding: 0.25em 0.4em;
    font-size: 75%;
    font-weight: 700;
    line-height: 1;
    text-align: center;
    white-space: nowrap;
    vertical-align: baseline;
    border-radius: 0.25rem;
    color: #fff;
}
.badge.pending { background-color: #6c757d; }
.badge.running { background-color: #007bff; }
.badge.success { background-color: #28a745; }
.badge.failed { background-color: #dc3545; }
</style>
