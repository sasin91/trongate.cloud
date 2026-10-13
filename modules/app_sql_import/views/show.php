<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        SQL Import Details
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
                <div class="detail-label">Customer Id</div>
                <div class="detail-value"><?= out($customer_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Task Id</div>
                <div class="detail-value"><?= out($task_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Log Id</div>
                <div class="detail-value"><?= out($log_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">File Name</div>
                <div class="detail-value"><?= out($file_name) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Bytes</div>
                <div class="detail-value"><?= out($bytes) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Compressed</div>
                <div class="detail-value"><?= out($compressed) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Status</div>
                <div class="detail-value"><?= out($status) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Sql Bytes</div>
                <div class="detail-value"><?= out($sql_bytes) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Total Statements</div>
                <div class="detail-value"><?= out($total_statements) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Summary</div>
                <div class="detail-content"><?= nl2br(out($summary)) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Position</div>
                <div class="detail-value"><?= out($position) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Line</div>
                <div class="detail-value"><?= out($line) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Statement Number</div>
                <div class="detail-value"><?= out($statement_number) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Statements Run</div>
                <div class="detail-value"><?= out($statements_run) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Statements Skipped</div>
                <div class="detail-value"><?= out($statements_skipped) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Error</div>
                <div class="detail-content"><?= nl2br(out($error)) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Can Continue</div>
                <div class="detail-value"><?= out($can_continue) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Skip First</div>
                <div class="detail-value"><?= out($skip_first) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">File Deleted</div>
                <div class="detail-value"><?= out($file_deleted) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?= out($created_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Updated At</div>
                <div class="detail-value"><?= out($updated_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Completed At</div>
                <div class="detail-value"><?= out($completed_at) ?></div>
            </div>
        </div>
    </div>
</div>
