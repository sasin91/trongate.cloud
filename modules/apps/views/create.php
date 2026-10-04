<div class="wz-embedded">
    <?php $q_current = 1; $q_total = $form === false ? 1 : 4; require __DIR__ . '/_wizard_top.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>

    <?php if ($form === false): ?>
        <div class="wz-wrap">
            <main class="wz-main">
                <?= form_open('projects/' . (int) $project->id . '/apps/source', ['class' => 'wz-stage']) ?>
                    <div class="wz-q">
                        <span class="wz-num">1 &rarr;</span>
                        <h1>Let's connect GitHub.</h1>
                        <p>You have not connected GitHub yet. Paste a personal access token so we can list your repositories.</p>
                    </div>

                    <?php $errors = validation_errors('<p>', '</p>'); ?>
                    <?php if ($errors): ?><div class="wz-errors" role="alert"><?= $errors ?></div><?php endif; ?>

                    <?= form_hidden('provider', 'github') ?>

                    <label class="wz-field">
                        <span class="wz-label">Personal access token</span>
                        <input type="password" name="token" class="wz-in mono" placeholder="ghp_..." required autocomplete="off" autofocus>
                    </label>

                    <div class="wz-help">
                        <strong>Getting a token takes a minute</strong>
                        <ol>
                            <li>Open <a href="https://github.com/settings/tokens" target="_blank" rel="noopener">GitHub settings, Tokens (classic)</a>.</li>
                            <li>Choose <strong>Generate new token (classic)</strong>.</li>
                            <li>Tick the <code>repo</code> scope, generate, and paste it here.</li>
                        </ol>
                    </div>

                    <div class="wz-nav">
                        <button type="submit" class="wz-ok">Connect</button>
                        <a href="projects/<?= (int) $project->id ?>" class="wz-back">Cancel</a>
                    </div>
                <?= form_close() ?>
            </main>
        </div>
    <?php else: ?>
        <?php
        extract($form);
        $form_action = 'projects/' . (int) $project->id . '/apps/submit';
        $branches_url = 'apps/branches';
        $q_offset = 0;
        $back_href = 'projects/' . (int) $project->id;
        require __DIR__ . '/_app_steps.php';
        ?>
    <?php endif; ?>
</div>
