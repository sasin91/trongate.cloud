<?php
/**
 * Project page header: name, counts, New app and the section tabs. Shared by
 * the project page (show.php), its traffic page (traffic.php) and its services page (services.php).
 *
 * Expects: $project, $app_count, $member_count, $is_owner, $active_tab ('apps', 'traffic' or 'services').
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
            <a href="<?= $e($new_app_href) ?>-git" class="btn-secondary">New app on trongate.cloud git</a>
            <a href="<?= $e($new_app_href) ?>" class="btn-primary">New app</a>
        </div>
    </div>

<?php
$project_href = 'projects/' . (int) $project->id;
$tabs = [
    [$project_href, 'Apps', $active_tab === 'apps'],
    [$project_href . '/traffic', 'Traffic', $active_tab === 'traffic'],
    [$project_href . '/services', 'Services', $active_tab === 'services'],
    [$project_href . '#members', 'Members', false],
];
if ($is_owner) {
    $tabs[] = [$project_href . '/settings', 'Settings', false];
}
$tabs_label = 'Project sections';
require __DIR__ . '/../../apps/views/_section_tabs.php';
