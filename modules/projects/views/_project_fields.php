<?php
/**
 * New-project fields shared by onboarding/project and projects/create.
 * Expects: $provider, $repositories, $project_types, $branches_url (MX endpoint returning <option>s).
 */
$branches = [];
?>
<?= form_hidden('provider', $provider) ?>

<div class="form-group">
    <label for="repository" class="form-label">Repository</label>
    <select
        name="repository"
        id="repository"
        class="form-select"
        required
        mx-get="<?= htmlspecialchars($branches_url) ?>?repository=${this.value}&provider=<?= htmlspecialchars($provider) ?>"
        mx-trigger="change"
        mx-target="#repository_branch"
        mx-swap="innerHTML"
    >
        <option value="">-- Select a repository --</option>
        <?php foreach ($repositories as $repo): ?>
            <option value="<?= htmlspecialchars($repo['full_name']) ?>" <?= post('repository') === $repo['full_name'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($repo['full_name']) ?>
                <?= $repo['private'] ? '(private)' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-group">
    <label for="repository_branch" class="form-label">Branch</label>
    <select name="repository_branch" id="repository_branch" class="form-select" required>
        <option value="">-- Select a branch --</option>
        <?php require __DIR__ . '/_repository_branch_options.php'; ?>
    </select>
    <p class="form-hint">Every push to this branch deploys automatically once the GitHub webhook is set up.</p>
</div>

<div class="form-group">
    <label for="label" class="form-label">Project Name</label>
    <input type="text" name="label" id="label" class="form-input" placeholder="e.g. My Awesome App" value="<?= htmlspecialchars((string) post('label')) ?>" required>
    <p class="form-hint">Your app gets a free address at <code>{name}.<?= htmlspecialchars(PLATFORM_APPS_DOMAIN) ?></code>.</p>
</div>

<div class="form-group">
    <label for="domain" class="form-label">Custom Domain (optional)</label>
    <input type="text" name="domain" id="domain" class="form-input" placeholder="e.g. app.example.com" value="<?= htmlspecialchars((string) post('domain')) ?>">
    <p class="form-hint">Point a CNAME record for this domain at <code><?= htmlspecialchars(PLATFORM_APPS_DOMAIN) ?></code> (or A/AAAA records for a bare domain, listed in the project settings). You can add it later there.</p>
</div>

<div class="form-group">
    <label for="type" class="form-label">Project Type</label>
    <select name="type" id="type" class="form-select" required>
        <?php $selected_type = post('type') ?: 'trongate_v2'; ?>
        <?php foreach ($project_types as $value => $type_label): ?>
            <option value="<?= $value ?>" <?= $value === $selected_type ? 'selected' : '' ?>><?= htmlspecialchars($type_label) ?></option>
        <?php endforeach; ?>
    </select>
</div>
