<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connect Source Control - Trongate Cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>customer-onboarding_module/css/onboarding.css">
</head>

<body>

    <div class="onboarding-card">
        <div class="onboarding-header">
            <h1>🐙 Connect Source Control</h1>
            <p>Link your GitHub account to deploy from your repositories.</p>
        </div>

        <?= form_open('customer-onboarding/submit_source', ['class' => 'highlight-errors']) ?>

        <?= validation_errors() ?>

        <div class="provider-info" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <h3 style="margin: 0; font-size: 1.1rem;">GitHub</h3>
            </div>
            <p style="margin: 0 0 10px; color: #666; font-size: 0.9rem;">
                Connect your GitHub account to deploy from your repositories.
            </p>
            <div class="instructions" style="font-size: 0.85rem; color: #555;">
                <strong>How to get your Personal Access Token:</strong>
                <ol style="margin: 5px 0 0 20px; padding: 0;">
                    <li>Go to <a href="https://github.com/settings/tokens" target="_blank" rel="noopener">GitHub Settings &rarr; Tokens</a>.</li>
                    <li>Click <strong>Generate new token (classic)</strong>.</li>
                    <li>Select scopes: <code>repo</code> (full access to private repos).</li>
                    <li>Click <strong>Generate token</strong> and copy it.</li>
                </ol>
            </div>
        </div>

        <input type="hidden" name="provider" value="github">

        <div class="form-group">
            <label for="token" class="form-label">Personal Access Token</label>
            <input type="password" name="token" id="token" class="form-input" placeholder="Paste your GitHub token" required>
        </div>

        <div class="steps">
            <div class="step-dot completed"></div>
            <div class="step-dot completed"></div>
            <div class="step-dot completed"></div>
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
        </div>

        <button type="submit" class="btn-primary">
            <div class="spinner" style="display: none;"></div>
            Connect & Continue
        </button>

        <?= form_close() ?>
    </div>

    <script src="<?= BASE_URL ?>customer-onboarding_module/js/onboarding.js"></script>
</body>

</html>