<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add SSH Key - Trongate Cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>customer-onboarding_module/css/onboarding.css">
</head>

<body>

    <div class="onboarding-card">
        <div class="onboarding-header">
            <h1>🔑 Add SSH Key</h1>
            <p>Provide your SSH public key to access your deployed servers.</p>
        </div>

        <?= form_open('customer-onboarding/submit_ssh_key', ['class' => 'highlight-errors']) ?>

        <?= validation_errors() ?>

        <div class="provider-info" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <h3 style="margin: 0; font-size: 1.1rem;">SSH Public Key</h3>
            </div>
            <p style="margin: 0 0 10px; color: #666; font-size: 0.9rem;">
                Your SSH key will be added to all servers we provision for you, allowing secure access.
            </p>
            <div class="instructions" style="font-size: 0.85rem; color: #555;">
                <strong>How to get your SSH public key:</strong>
                <ol style="margin: 5px 0 0 20px; padding: 0;">
                    <li>Open a terminal on your computer.</li>
                    <li>Run: <code style="background: #e9ecef; padding: 2px 6px; border-radius: 3px;">cat ~/.ssh/id_rsa.pub</code> or <code style="background: #e9ecef; padding: 2px 6px; border-radius: 3px;">cat ~/.ssh/id_ed25519.pub</code></li>
                    <li>Copy the entire output (starts with <code>ssh-rsa</code> or <code>ssh-ed25519</code>).</li>
                    <li>If you don't have a key, generate one with: <code style="background: #e9ecef; padding: 2px 6px; border-radius: 3px;">ssh-keygen -t ed25519</code></li>
                </ol>
            </div>
        </div>

        <input type="hidden" name="provider" value="hetzner">

        <div class="form-group">
            <?= form_label('Key Label (optional)', ['for' => 'label', 'class' => 'form-label']) ?>
            <?= form_input('label', post('label', ''), ['class' => 'form-input', 'placeholder' => 'e.g., My Work Laptop']) ?>
        </div>

        <div class="form-group">
            <?= form_label('SSH Public Key', ['for' => 'public_key', 'class' => 'form-label']) ?>
            <?= form_textarea('public_key', post('public_key', ''), [
                'class' => 'form-input', 
                'rows' => 6, 
                'placeholder' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... user@example.com', 
                'style' => 'font-family: monospace; font-size: 0.85rem;'
            ]) ?>
            <small style="color: #666; font-size: 0.8rem;">Paste your entire public key (usually found in ~/.ssh/id_rsa.pub or ~/.ssh/id_ed25519.pub)</small>
        </div>

        <div class="steps">
            <div class="step-dot completed"></div>
            <div class="step-dot completed"></div>
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
        </div>

        <button type="submit" class="btn-primary">
            Save & Continue
        </button>
        <?= form_close() ?>
    </div>

</body>

</html>
