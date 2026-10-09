<?php
/**
 * Project page header: name, counts, New app and the section tabs. Shared by
 * the project page (show.php) and its traffic page (traffic.php).
 *
 * Expects: $project, $app_count, $member_count, $is_owner, $active_tab ('apps' or 'traffic').
 */
$e = fn($value) => htmlspecialchars((string) $value);
$new_app_href = 'projects/' . (int) $project->id . '/apps/new';
?>
    <div class="ov-head">
        <div class="ov-title">
            <div class="ov-title-row">
                <h1><?= $e($project->name) ?></h1>
            </div>
            <div class="ov-meta">
                <span><?= $app_count ?> <?= $app_count === 1 ? 'app' : 'apps' ?></span>
                <span><?= $member_count ?> <?= $member_count === 1 ? 'member' : 'members' ?></span>
                <span><?= $is_owner ? 'You own this project' : 'You are a member' ?></span>
            </div>
        </div>
        <div class="ov-actions">
            <a href="<?= $e($new_app_href) ?>" class="btn-primary">New app</a>
        </div>
    </div>

    <nav class="ov-tabs" aria-label="Project sections">
        <a href="projects/<?= (int) $project->id ?>"<?= $active_tab === 'apps' ? ' aria-current="page"' : '' ?>>Apps</a>
        <a href="projects/<?= (int) $project->id ?>/traffic"<?= $active_tab === 'traffic' ? ' aria-current="page"' : '' ?>>Traffic</a>
        <a href="projects/<?= (int) $project->id ?>#services">Services</a>
        <a href="projects/<?= (int) $project->id ?>#members">Members</a>
        <?php if ($is_owner): ?>
            <a href="projects/<?= (int) $project->id ?>/settings">Settings</a>
        <?php endif; ?>
    </nav>

