<?php
/**
 * The Diagram view of views/index.php (App_database_diagram::_diagram_data). Expects: $diagram
 * (Schema_diagram::layout), $diagram_cards (its cards with href), $diagram_all_url, $diagram_around_url
 * (null when no table is open), $diagram_trongate_url, $diagram_around, $diagram_trongate, $diagram_summary,
 * and $view_toggle, $sql_url for the status bar.
 */
$e = fn($value) => htmlspecialchars((string) $value);
?>
<div class="db-rows db-diagram">
    <div class="db-diagram-tools">
        <nav class="db-seg" aria-label="Diagram scope">
            <a href="<?= $e($diagram_all_url) ?>"<?= $diagram_around ? '' : ' aria-current="page"' ?>>All tables</a>
            <?php if ($diagram_around_url !== null): ?>
                <a href="<?= $e($diagram_around_url) ?>"<?= $diagram_around ? ' aria-current="page"' : '' ?>>Around this table</a>
            <?php endif; ?>
        </nav>
        <a class="db-diagram-check" role="checkbox" aria-checked="<?= $diagram_trongate ? 'true' : 'false' ?>" href="<?= $e($diagram_trongate_url) ?>">
            <span class="db-diagram-box"></span>Trongate tables<?php if ($diagram['hidden'] > 0): ?> <span class="db-diagram-faint">(<?= (int) $diagram['hidden'] ?> hidden)</span><?php endif; ?>
        </a>
        <span class="db-diagram-legend">
            <span><svg viewBox="0 0 28 8" aria-hidden="true"><path d="M0 4h28"/></svg>Foreign key</span>
            <span><svg viewBox="0 0 28 8" aria-hidden="true"><path class="db-diagram-inferred" d="M0 4h28"/></svg>Trongate relation (<code>_id</code> column, no constraint)</span>
        </span>
    </div>

    <div class="db-diagram-canvas">
        <?php if ($diagram_cards === []): ?>
            <p class="db-diagram-empty">No tables to draw yet.</p>
        <?php else: ?>
            <div class="db-diagram-plane" style="width: <?= (int) $diagram['width'] ?>px; height: <?= (int) $diagram['height'] ?>px">
                <svg class="db-diagram-wires" width="<?= (int) $diagram['width'] ?>" height="<?= (int) $diagram['height'] ?>" aria-hidden="true">
                    <?php foreach ($diagram['wires'] as $wire): ?>
                        <g class="<?= $wire['declared'] ? '' : 'db-diagram-inferred' ?><?= $wire['dim'] ? ' db-diagram-dim' : '' ?>">
                            <path class="db-diagram-line" d="<?= $e($wire['path']) ?>"/>
                            <path d="<?= $e($wire['parent_mark']) ?>"/>
                            <path d="<?= $e($wire['child_mark']) ?>"/>
                        </g>
                    <?php endforeach; ?>
                </svg>

                <?php foreach ($diagram['headings'] as $heading): ?>
                    <span class="db-diagram-heading" style="left: <?= (int) $heading['x'] ?>px; top: <?= (int) $heading['y'] ?>px"><?= $e($heading['text']) ?></span>
                <?php endforeach; ?>

                <?php foreach ($diagram_cards as $card): ?>
                    <a class="db-diagram-card" href="<?= $e($card['href']) ?>" style="left: <?= (int) $card['x'] ?>px; top: <?= (int) $card['y'] ?>px"<?= $card['current'] ? ' aria-current="page"' : '' ?>>
                        <span class="db-diagram-name"><?= $e($card['name']) ?><span><?= $e($card['rows']) ?></span></span>
                        <?php foreach ($card['columns'] as $column): ?>
                            <span class="db-diagram-column db-diagram-<?= $e($column['marker']) ?>"><b><?= $e($column['name']) ?></b><span><?= $e($column['type']) ?></span></span>
                        <?php endforeach; ?>
                        <?php if ($card['more'] > 0): ?>
                            <span class="db-diagram-column db-diagram-faint">+ <?= (int) $card['more'] ?> more</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="db-status">
        <?php require __DIR__ . '/_toggle.php'; ?>
        <span class="db-status-text"><?= $e($diagram_summary) ?></span>
    </div>
</div>
