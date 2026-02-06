<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Project - Trongate Cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>customer-onboarding_module/css/onboarding.css">
    <script src="<?= BASE_URL ?>js/trongate-mx.min.js"></script>
</head>

<body>

    <div class="onboarding-card">
        <div class="onboarding-header">
            <h1>Create your First Project</h1>
            <p>Set up a project to organize your resources.</p>
        </div>

        <?= validation_errors() ?>

        <?= form_open('customer-onboarding/submit_project') ?>
        
        <?= form_hidden('provider', $provider) ?>

        <div class="form-group">
            <label for="repository" class="form-label">Repository</label>
            <select 
                name="repository" 
                id="repository" 
                class="form-select" 
                required
                mx-get="customer-onboarding/repository_branch_options?repository=${this.value}&provider=<?= $provider ?>"
                mx-trigger="change"
                mx-target="#repository_branch"
                mx-swap="innerHTML"
            >
                <option value="">-- Select a repository --</option>
                <?php foreach ($repositories as $repo): ?>
                    <option value="<?= htmlspecialchars($repo['full_name']) ?>">
                        <?= htmlspecialchars($repo['full_name']) ?>
                        <?= $repo['private'] ? '(private)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="repository_branch" class="form-label">Branch</label>
            <select 
                name="repository_branch" 
                id="repository_branch" 
                class="form-select" 
                required
            >
                <option value="">-- Select a branch --</option>
                <?php include __DIR__ . '/_repository_branch_options.php'; ?>
            </select>
        </div>

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
            <div class="step-dot completed"></div>
            <div class="step-dot active"></div>
        </div>

        <button type="submit" class="btn-primary">
            <div class="spinner" style="display: none;"></div>
            Launch Dashboard
        </button>
        <?= form_close() ?>
    </div>

    <script src="<?= BASE_URL ?>customer-onboarding_module/js/onboarding.js"></script>
    <script src="<?= BASE_URL ?>customer-onboarding_module/js/project.js"></script>
</body>

</html>