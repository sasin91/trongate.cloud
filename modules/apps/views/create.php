<div class="wz-embedded">
    <?php $q_current = 1; $q_total = $form === false ? 1 : 6; require __DIR__ . '/_wizard_top.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>

    <?php if ($form === false): ?>
        <div class="wz-wrap">
            <main class="wz-main">
                <div class="wz-stage">
                    <div class="wz-q">
                        <span class="wz-num">1 &rarr;</span>
                        <h1>Let's connect GitHub.</h1>
                        <p>Sign in with GitHub so we can list the repositories the trongate.cloud app may read.</p>
                    </div>

                    <div class="wz-nav">
                        <a href="auth/github?then=projects/<?= (int) $project->id ?>/apps/new" class="wz-ok">Connect GitHub</a>
                        <a href="projects/<?= (int) $project->id ?>" class="wz-back">Cancel</a>
                    </div>
                </div>
            </main>
        </div>
    <?php else: ?>
        <?php
        extract($form);
        $form_action = 'projects/' . (int) $project->id . '/apps/submit';
        $branches_url = 'apps/branches';
        $env_url = 'apps/env-suggestions';
        $q_offset = 0;
        $back_href = 'projects/' . (int) $project->id;
        require __DIR__ . '/_app_steps.php';
        ?>
    <?php endif; ?>
</div>
