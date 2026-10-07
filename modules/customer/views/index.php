<div class="content">
    <?= flashdata('<p class="flash">', '</p>') ?>

    <div class="section-header">
        <h2>Projects</h2>
        <?php if (count($projects) > 0): ?>
            <a href="projects/new" class="btn-secondary">New project</a>
        <?php endif; ?>
    </div>

    <?php foreach ($projects as $project): ?>
        <section class="project-block" aria-labelledby="project-<?= (int) $project->id ?>">
            <div class="section-header">
                <h3 id="project-<?= (int) $project->id ?>"><a href="projects/<?= (int) $project->id ?>"><?= htmlspecialchars($project->name) ?></a></h3>
                <a href="projects/<?= (int) $project->id ?>/apps/new" class="btn-create">+ New app</a>
            </div>
            <?php
            $apps = $project->apps;
            $new_app_href = 'projects/' . (int) $project->id . '/apps/new';
            require APPPATH . 'modules/projects/views/_app_cards.php';
            ?>
        </section>
    <?php endforeach; ?>

    <?php if (count($projects) === 0): ?>
        <div class="empty-card">
            <h3>You are not in any project yet.</h3>
            <p>A project holds your apps and the people who work on them. Start one, or ask a project owner to add you by your email address.</p>
            <a href="projects/new" class="btn-create">New project</a>
        </div>
    <?php endif; ?>

    <p class="dashboard-aside"><a href="integrations/claude">Use Claude with trongate.cloud</a>: deploy, read logs and fix your databases by asking.</p>
</div>
