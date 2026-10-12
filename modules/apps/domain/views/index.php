<?php
/**
 * apps/{id}/domain (Domain::index). Expects: $app, $urls, $live_state, $can_rollback,
 * $domain_input (the form field), $platform_host ({slug}.trongate.dev), $domain (saved, '' for none), $steps, $summary,
 * $verify_label ('' hides the button) and $records (Domain::_unchecked_records,
 * replaced by _records.php once the lookups are done).
 */
?>
<div class="ov">
    <?php $active_tab = 'domain'; require APPPATH . 'modules/apps/views/_app_header.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>
    <?= validation_errors() ?>

    <div class="card settings-card domain-card">
        <h2>Custom domain</h2>
        <?= form_open('apps/' . $app->id . '/domain/submit', ['class' => 'domain-form']) ?>
            <div class="form-group">
                <label for="domain" class="form-label">Domain</label>
                <input type="text" name="domain" id="domain" class="form-input mono" placeholder="app.example.com" autocomplete="off" value="<?= htmlspecialchars($domain_input) ?>">
                <?php if ($domain === ''): ?>
                <p class="form-hint"><code><?= htmlspecialchars($platform_host) ?></code> is your staging address. Add your own domain when you're ready to go live.</p>
                <?php else: ?>
                <p class="form-hint">Your app also stays on <code><?= htmlspecialchars($platform_host) ?></code>. Clear the field and save to remove the domain.</p>
                <?php endif; ?>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Save domain</button>
            </div>
        <?= form_close() ?>
    </div>

    <?php if ($domain !== ''): ?>
    <div class="card settings-card domain-card">
        <h2><?= htmlspecialchars($domain) ?></h2>

        <ol class="domain-steps">
            <?php foreach ($steps as $step): ?>
            <li class="domain-step is-<?= $step['state'] ?>"><?= htmlspecialchars($step['label']) ?></li>
            <?php endforeach; ?>
        </ol>

        <p class="domain-summary"><?= htmlspecialchars($summary) ?></p>

        <div id="domain-records" mx-get="apps/<?= (int) $app->id ?>/domain/records" mx-trigger="load">
            <?php require __DIR__ . '/_records.php'; ?>
        </div>

        <div class="domain-notes">
            <p><strong>Using Cloudflare?</strong> Set these records to <em>DNS only</em> (grey cloud), the way trongate.cloud itself is set up. We serve your app and issue its HTTPS certificate ourselves; a proxied record hides our address, so the domain can't be checked or secured. Cloudflare takes the full names shown here.</p>
            <p>Delete any other A, AAAA or CNAME records for <code><?= htmlspecialchars($domain) ?></code>. A subdomain may use a CNAME to <code><?= htmlspecialchars($platform_host) ?></code> instead of the A and AAAA records.</p>
        </div>

        <?php if ($verify_label !== ''): ?>
        <?= form_open('apps/' . $app->id . '/domain/verify', ['class' => 'form-actions']) ?>
            <button type="submit" class="btn-primary"><?= htmlspecialchars($verify_label) ?></button>
        <?= form_close() ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
