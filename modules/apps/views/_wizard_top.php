<?php
/**
 * Top bar of the one-question flow: brand, "Question n of m" and the progress segments.
 * Expects: $q_current (1-based, the first question on this page), $q_total.
 */
?>
<header class="wz-top">
    <div class="wz-top-row">
        <a href="<?= BASE_URL ?>" class="wz-brand">
            <?php require APPPATH . 'modules/templates/views/_mark.php'; ?>
            <span>trongate<b>.cloud</b></span>
        </a>
        <span class="wz-counter" id="wz-counter">Question <?= (int) $q_current ?> of <?= (int) $q_total ?></span>
    </div>
    <div class="wz-progress" id="wz-progress" aria-hidden="true">
        <?php for ($i = 1; $i <= $q_total; $i++): ?>
            <span class="<?= $i < $q_current ? 'done' : ($i === (int) $q_current ? 'active' : '') ?>"></span>
        <?php endfor; ?>
    </div>
</header>
