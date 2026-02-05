<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Project - Trongate Cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>customer-onboarding_module/css/onboarding.css">
</head>

<body>

    <div class="onboarding-card">
        <div class="onboarding-header">
            <h1>Create your First Project</h1>
            <p>Set up a project to organize your resources.</p>
        </div>

        <?= validation_errors() ?>

        <?= form_open('customer-onboarding/submit_project') ?>
        <div class="form-group">
            <label for="label" class="form-label">Project Name</label>
            <input type="text" name="label" id="label" class="form-input" placeholder="e.g. My Awesome App" value="<?= htmlspecialchars(post('label') ?? '') ?>" required autofocus>
        </div>

        <div class="form-group">
            <label for="type" class="form-label">Project Type</label>
            <select name="type" id="type" class="form-select" required>
                <?php
                $selected = post('type') ?? 'trongate_v2';
                $options = [
                    'trongate_v2' => 'Trongate v2',
                    'trongate_v1' => 'Trongate v1'
                ];
                foreach ($options as $val => $label) {
                    $sel_attr = ($val === $selected) ? 'selected' : '';
                    echo "<option value=\"$val\" $sel_attr>$label</option>";
                }
                ?>
            </select>
        </div>

        <div class="steps">
            <div class="step-dot completed"></div>
            <div class="step-dot completed"></div>
            <div class="step-dot completed"></div>
            <div class="step-dot completed"></div>
            <div class="step-dot active"></div>
        </div>

        <button type="submit" class="btn-primary">
            Launch Dashboard
        </button>
        <?= form_close() ?>
    </div>

</body>

</html>