<?php
// The public site's top bar (landing page and news). Anchors resolve against <base>, so they
// scroll on the landing page and lead back to it from elsewhere.
?>
<header class="lp-top">
    <a href="<?= BASE_URL ?>" class="lp-brand">
        <?php require __DIR__ . '/_mark.php'; ?>
        <span>trongate<b>.cloud</b></span>
    </a>
    <nav class="lp-nav" aria-label="Main">
        <a href="#how">How it works</a>
        <a href="#stability">Stability</a>
        <a href="#features">Features</a>
        <a href="#faq">FAQ</a>
        <a href="news"<?= str_starts_with(segment(1), 'news') ? ' aria-current="page"' : '' ?>>News</a>
    </nav>
    <div class="lp-top-actions">
        <a href="login" class="lp-login">Log in</a>
        <a href="register" class="lp-btn">Start free</a>
    </div>
</header>
