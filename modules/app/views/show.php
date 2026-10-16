<h1><?= $headline ?></h1>
<?= flashdata() ?>
<div class="card">
    <div class="card-heading">
        App Details
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
                <div class="detail-label">Label</div>
                <div class="detail-value"><?= out($label) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Slug</div>
                <div class="detail-value"><?= out($slug) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Serves Slug Host</div>
                <div class="detail-value"><?= out($serves_slug_host) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Type</div>
                <div class="detail-value"><?= out($type) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Environment</div>
                <div class="detail-value"><?= out($environment) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Production App Id</div>
                <div class="detail-value"><?= out($production_app_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Domain</div>
                <div class="detail-value"><?= out($domain) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Verified Domain</div>
                <div class="detail-value"><?= out($verified_domain) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Domain Pointed At</div>
                <div class="detail-value"><?= out($domain_pointed_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Domain Secured At</div>
                <div class="detail-value"><?= out($domain_secured_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Domain Dns Failures</div>
                <div class="detail-value"><?= out($domain_dns_failures) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Domain Checked At</div>
                <div class="detail-value"><?= out($domain_checked_at) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Source Url</div>
                <div class="detail-value"><?= out($source_url) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Source Branch</div>
                <div class="detail-value"><?= out($source_branch) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Source Provider</div>
                <div class="detail-value"><?= out($source_provider) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Auto Deploy</div>
                <div class="detail-value"><?= out($auto_deploy) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Reconciled At</div>
                <div class="detail-value"><?= out($reconciled_at) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Persistent Paths</div>
                <div class="detail-content"><?= nl2br(out($persistent_paths)) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Always On</div>
                <div class="detail-value"><?= out($always_on) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Trongate Env</div>
                <div class="detail-value"><?= out($trongate_env) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Mail Capture</div>
                <div class="detail-value"><?= out($mail_capture) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Health Path</div>
                <div class="detail-value"><?= out($health_path) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Setup Sql Status</div>
                <div class="detail-value"><?= out($setup_sql_status) ?></div>
            </div>
            <div class="detail-block">
                <div class="detail-label">Setup Sql Error</div>
                <div class="detail-content"><?= nl2br(out($setup_sql_error)) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Database Server Id</div>
                <div class="detail-value"><?= out($database_server_id) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Current Deployment Id</div>
                <div class="detail-value"><?= out($current_deployment_id) ?></div>
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
