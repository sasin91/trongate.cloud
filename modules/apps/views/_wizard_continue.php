<?php
/**
 * Asked when the new-app questions are opened again while earlier answers
 * are kept (App_wizard_draft): continue them, or start over.
 * Shared by projects/{id}/apps/new and onboarding/app.
 *
 * Expects: $draft_name, $continue_url (the question the answers stopped at),
 * $reset_action (forgets them, then opens the first question).
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div class="wz-wrap">
    <main class="wz-main">
        <?= form_open($reset_action, ['class' => 'wz-stage']) ?>
            <div class="wz-q">
                <h1>Pick up where you left off?</h1>
                <p>You were setting up <strong><?= $e($draft_name) ?></strong>.</p>
            </div>
            <div class="wz-nav">
                <a href="<?= $e(BASE_URL . $continue_url) ?>" class="wz-ok">Continue</a>
                <button type="submit" class="wz-back">Start over</button>
            </div>
        <?= form_close() ?>
    </main>
</div>
