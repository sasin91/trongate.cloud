<?php
// The docs sidebar: every page in order. Expects $pages and $current (a slug, or '' on the index).
?>
<nav class="lp-docs-nav" aria-label="Documentation">
    <a href="docs" class="lp-docs-nav-home"<?= $current === '' ? ' aria-current="page"' : '' ?>>Documentation</a>
    <ol>
        <?php foreach ($pages as $item): ?>
            <li><a href="docs/<?= $item->slug ?>"<?= $item->slug === $current ? ' aria-current="page"' : '' ?>><?= out($item->title) ?></a></li>
        <?php endforeach; ?>
    </ol>
</nav>
