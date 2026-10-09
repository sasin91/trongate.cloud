<?php
/**
 * Section tabs for the app and project headers: an underlined row of links, and on
 * phones a dropdown that opens the chosen section, so no row has to be panned sideways.
 *
 * Expects: $tabs (list of [href, label, is_current]) and $tabs_label (e.g. 'App sections').
 */
?>
    <div class="ov-tabs-select">
        <select aria-label="<?= htmlspecialchars($tabs_label) ?>" onchange="window.location.href = this.value">
            <?php foreach ($tabs as [$href, $label, $is_current]): ?>
                <option value="<?= htmlspecialchars(BASE_URL . $href) ?>"<?= $is_current ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
        <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"/></svg>
    </div>

    <nav class="ov-tabs" aria-label="<?= htmlspecialchars($tabs_label) ?>">
        <?php foreach ($tabs as [$href, $label, $is_current]): ?>
            <a href="<?= htmlspecialchars($href) ?>"<?= $is_current ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
    </nav>
