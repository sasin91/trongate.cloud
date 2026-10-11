<?php
/**
 * The Database tab's Diagram view (App_database_diagram::_diagram_data). Expects: $diagram
 * (Schema_diagram::layout), $diagram_cards (its cards with href), $diagram_all_url, $diagram_around_url
 * (null when no table is open), $diagram_trongate_url, $diagram_around, $diagram_trongate, $diagram_summary.
 */
?>
<div class="db-diagram">
    <div class="db-diagram-tools">
        <nav class="db-seg" aria-label="Diagram scope">
            <a href="<?= out($diagram_all_url) ?>"<?= $diagram_around ? '' : ' aria-current="page"' ?>>All tables</a>
            <?php if ($diagram_around_url !== null) { ?>
                <a href="<?= out($diagram_around_url) ?>"<?= $diagram_around ? ' aria-current="page"' : '' ?>>Around this table</a>
            <?php } ?>
        </nav>
        <a class="db-diagram-check" role="checkbox" aria-checked="<?= $diagram_trongate ? 'true' : 'false' ?>" href="<?= out($diagram_trongate_url) ?>">
            <span class="db-diagram-box"></span>Trongate tables<?php if ($diagram['hidden'] > 0) { ?> <span class="db-diagram-faint">(<?= (int) $diagram['hidden'] ?> hidden)</span><?php } ?>
        </a>
        <span class="db-diagram-legend">
            <span><svg viewBox="0 0 28 8" aria-hidden="true"><path d="M0 4h28"/></svg>Foreign key</span>
            <span><svg viewBox="0 0 28 8" aria-hidden="true"><path class="db-diagram-inferred" d="M0 4h28"/></svg>Trongate relation (<code>_id</code> column, no constraint)</span>
        </span>
    </div>

    <div class="db-diagram-canvas">
        <?php if ($diagram_cards === []) { ?>
            <p class="db-diagram-empty">No tables to draw yet.</p>
        <?php } else { ?>
            <div class="db-diagram-plane" style="width: <?= (int) $diagram['width'] ?>px; height: <?= (int) $diagram['height'] ?>px">
                <svg class="db-diagram-wires" width="<?= (int) $diagram['width'] ?>" height="<?= (int) $diagram['height'] ?>" aria-hidden="true">
                    <?php foreach ($diagram['wires'] as $wire) { ?>
                        <g class="<?= $wire['declared'] ? '' : 'db-diagram-inferred' ?><?= $wire['dim'] ? ' db-diagram-dim' : '' ?>">
                            <path class="db-diagram-line" d="<?= out($wire['path']) ?>"/>
                            <path d="<?= out($wire['parent_mark']) ?>"/>
                            <path d="<?= out($wire['child_mark']) ?>"/>
                        </g>
                    <?php } ?>
                </svg>

                <?php foreach ($diagram['headings'] as $heading) { ?>
                    <span class="db-diagram-heading" style="left: <?= (int) $heading['x'] ?>px; top: <?= (int) $heading['y'] ?>px"><?= out($heading['text']) ?></span>
                <?php } ?>

                <?php foreach ($diagram_cards as $card) { ?>
                    <a class="db-diagram-card" href="<?= out($card['href']) ?>" style="left: <?= (int) $card['x'] ?>px; top: <?= (int) $card['y'] ?>px"<?= $card['current'] ? ' aria-current="page"' : '' ?>>
                        <span class="db-diagram-name"><?= out($card['name']) ?><span><?= out($card['rows']) ?></span></span>
                        <?php foreach ($card['columns'] as $column) { ?>
                            <span class="db-diagram-column db-diagram-<?= out($column['marker']) ?>"><b><?= out($column['name']) ?></b><span><?= out($column['type']) ?></span></span>
                        <?php } ?>
                        <?php if ($card['more'] > 0) { ?>
                            <span class="db-diagram-column db-diagram-faint">+ <?= (int) $card['more'] ?> more</span>
                        <?php } ?>
                    </a>
                <?php } ?>
            </div>
        <?php } ?>
    </div>

    <p class="db-diagram-summary"><?= out($diagram_summary) ?></p>
</div>
