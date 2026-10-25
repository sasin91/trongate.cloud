<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    $page_title = $page->title . ' - trongate.cloud docs';
    $page_description = $page->summary;
    require APPPATH . 'modules/templates/views/_site_head.php';
    ?>
</head>

<body class="lp">
    <?php require APPPATH . 'modules/templates/views/_site_header.php'; ?>

    <main class="lp-wrap lp-news">
        <div class="lp-docs-grid">
            <?php $current = $page->slug; require __DIR__ . '/_sidebar.php'; ?>

            <article class="lp-post">
                <h1 class="lp-h2"><?= out($page->title) ?></h1>
                <?php if ($page->summary !== ''): ?>
                    <p class="lp-sub"><?= out($page->summary) ?></p>
                <?php endif; ?>
                <?php if (count($page->sections) >= 3): ?>
                    <nav class="lp-toc" aria-label="On this page">
                        <p class="lp-toc-title">On this page</p>
                        <ol>
                            <?php foreach ($page->sections as $id => $heading): ?>
                                <li><a href="docs/<?= $page->slug ?>#<?= $id ?>"><?= Markdown::inline($heading) ?></a></li>
                            <?php endforeach; ?>
                        </ol>
                    </nav>
                <?php endif; ?>
                <?php if ($page->sample !== ''): ?>
                    <aside class="lp-docs-sample">
                        <div>
                            <strong>Sample module</strong>
                            <p>Unzip it into your app's root folder. It adds these files:</p>
                            <ul>
                                <?php foreach ($page->sample_files as $file): ?>
                                    <li><code><?= out($file->path) ?></code> <span><?= $file->size ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <a href="docs/samples/<?= $page->sample ?>.zip" class="lp-btn" download>Download <?= $page->sample ?>.zip</a>
                    </aside>
                <?php endif; ?>
                <div class="lp-prose">
                    <?= $page->html ?>
                </div>
                <nav class="lp-docs-pager" aria-label="More pages">
                    <?php if ($page->previous !== null): ?>
                        <a href="docs/<?= $page->previous->slug ?>"><span>Previous</span><?= out($page->previous->title) ?></a>
                    <?php endif; ?>
                    <?php if ($page->next !== null): ?>
                        <a href="docs/<?= $page->next->slug ?>" class="lp-docs-pager-next"><span>Next</span><?= out($page->next->title) ?></a>
                    <?php endif; ?>
                </nav>
            </article>
        </div>
    </main>

    <?php require APPPATH . 'modules/templates/views/_site_footer.php'; ?>
</body>

</html>
