<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    $page_title = 'Documentation - trongate.cloud';
    $page_description = 'How to run your Trongate app on trongate.cloud: deploys, databases, backups and more.';
    require APPPATH . 'modules/templates/views/_site_head.php';
    ?>
</head>

<body class="lp">
    <?php require APPPATH . 'modules/templates/views/_site_header.php'; ?>

    <main class="lp-wrap lp-news">
        <div class="lp-docs-grid">
            <?php $current = ''; require __DIR__ . '/_sidebar.php'; ?>

            <div>
                <div class="lp-news-head">
                    <span class="lp-kicker">Documentation</span>
                    <h1 class="lp-h2">Run your Trongate app on trongate.cloud.</h1>
                    <p class="lp-sub">How deploys, databases and backups work, with sample modules to download.</p>
                </div>

                <?php if (empty($pages)): ?>
                    <p class="lp-news-empty">Nothing here yet.</p>
                <?php else: ?>
                    <ol class="lp-news-list">
                        <?php foreach ($pages as $item): ?>
                            <li>
                                <h2><a href="docs/<?= $item->slug ?>"><?= out($item->title) ?></a></h2>
                                <?php if ($item->summary !== ''): ?>
                                    <p><?= out($item->summary) ?></p>
                                <?php endif; ?>
                                <a href="docs/<?= $item->slug ?>" class="lp-link">Read page &rarr;</a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php require APPPATH . 'modules/templates/views/_site_footer.php'; ?>
</body>

</html>
