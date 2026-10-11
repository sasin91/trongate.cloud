<?php
/**
 * The Data | Structure switch at the left of the status bar; phones also get SQL.
 * Expects: $view_toggle ([href, label, is_current]), $sql_url.
 */
?>
<nav class="db-toggle" aria-label="Show">
    <?php foreach ($view_toggle as [$href, $label, $is_current]): ?>
        <a href="<?= htmlspecialchars($href) ?>"<?= $is_current ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
    <a href="<?= htmlspecialchars($sql_url) ?>" class="db-phone-only">SQL</a>
</nav>
