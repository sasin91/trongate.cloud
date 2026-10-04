<?php
$e = fn($value) => htmlspecialchars((string) $value);
$app_count = count($apps);
$member_count = count($members);
$new_app_href = 'projects/' . (int) $project->id . '/apps/new';
?>
<div class="ov">
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
        <a href="projects/<?= (int) $project->id ?>" aria-current="page">Apps</a>
        <a href="projects/<?= (int) $project->id ?>#members">Members</a>
        <?php if ($is_owner): ?>
            <a href="projects/<?= (int) $project->id ?>/settings">Settings</a>
        <?php endif; ?>
    </nav>

    <?= flashdata('<p class="flash">', '</p>') ?>

    <div class="ov-main">
        <div class="ov-col">
            <?php require __DIR__ . '/_app_cards.php'; ?>
        </div>

        <div class="ov-col">
            <section class="ov-card" id="members" aria-labelledby="members-h">
                <div class="ov-card-head">
                    <h2 id="members-h">Members</h2>
                    <span class="muted">see and deploy every app</span>
                </div>
                <ul class="member-list">
                    <?php foreach ($members as $member): ?>
                        <li>
                            <span class="member-who">
                                <span><?= $e($member->email) ?><?= (int) $member->customer_id === $customer_id ? ' (you)' : '' ?></span>
                                <small class="muted"><?= $e($member->role) ?></small>
                            </span>
                            <?php if ($is_owner && $member->role !== 'owner'): ?>
                                <?= form_open('projects/' . (int) $project->id . '/members/' . (int) $member->customer_id . '/remove', ['onsubmit' => 'return confirm(' . json_encode('Remove ' . $member->email . ' from this project?') . ')']) ?>
                                    <button type="submit" class="btn-link">Remove</button>
                                <?= form_close() ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($is_owner): ?>
                    <div class="member-add">
                        <?= validation_errors('<p class="form-hint">', '</p>') ?>
                        <?= form_open('projects/' . (int) $project->id . '/members/add') ?>
                            <div class="form-group">
                                <label for="email" class="form-label">Add a member by email</label>
                                <input type="email" name="email" id="email" class="form-input" placeholder="colleague@example.com" value="<?= $e($email) ?>" required maxlength="255">
                                <p class="form-hint">They need a trongate.cloud account already. Members can deploy, roll back and change app settings; only you can delete.</p>
                            </div>
                            <button type="submit" class="btn-secondary">Add member</button>
                        <?= form_close() ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
