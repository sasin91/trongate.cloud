<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Team - Trongate Cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>customer-onboarding_module/css/onboarding.css">
</head>

<body>

    <div class="onboarding-card">
        <div class="onboarding-header">
            <h1>Create your Team</h1>
            <p>Start by naming your team where your projects will live.</p>
        </div>

        <?= validation_errors('<div class="error-message">', '</div>') ?>

        <?= form_open('customer-onboarding/submit_team') ?>

        <div class="form-group">
            <label for="name" class="form-label">Team Name</label>
            <input type="text" name="name" id="name" class="form-input" placeholder="e.g. Acme Corp" value="<?= htmlspecialchars(post('name') ?? '') ?>" required autofocus>
        </div>

        <div class="steps">
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
        </div>

        <button type="submit" class="btn-primary">
            Create Team & Continue
        </button>
        <?= form_close() ?>
    </div>

</body>

</html>