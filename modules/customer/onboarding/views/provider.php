<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connect Hosting Provider - Trongate Cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>customer-onboarding_module/css/onboarding.css">
</head>

<body>

    <div class="onboarding-card">
        <div class="onboarding-header">
            <h1>☁️ Connect Hosting Provider</h1>
            <p>Link your cloud provider to deploy servers.</p>
        </div>

        <?= form_open('customer-onboarding/submit_provider', ['class' => 'highlight-errors']) ?>

        <?= validation_errors() ?>

        <div class="provider-info" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <h3 style="margin: 0; font-size: 1.1rem;">Hetzner Cloud</h3>
            </div>
            <p style="margin: 0 0 10px; color: #666; font-size: 0.9rem;">
                We currently support Hetzner Cloud for its excellent performance and pricing.
            </p>
            <div class="instructions" style="font-size: 0.85rem; color: #555;">
                <strong>How to get your API Token:</strong>
                <ol style="margin: 5px 0 0 20px; padding: 0;">
                    <li>Log in to <a href="https://console.hetzner.cloud" target="_blank" rel="noopener">Hetzner Cloud Console</a>.</li>
                    <li>Select your project (or create one).</li>
                    <li>Go to <strong>Security</strong> &rarr; <strong>API Tokens</strong>.</li>
                    <li>Click <strong>Generate API Token</strong> (Read & Write required).</li>
                </ol>
            </div>
        </div>

        <input type="hidden" name="provider" value="hetzner">

        <div class="form-group">
            <label for="token" class="form-label">API Token</label>
            <input type="password" name="token" id="token" class="form-input" placeholder="Paste your Hetzner API token" required>
        </div>

        <div class="steps">
            <div class="step-dot completed"></div>
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
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