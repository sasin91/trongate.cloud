<?php
$date = fn(string $ymd) => date('F j, Y', strtotime($ymd));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    $page_title = ($kind ? $kinds[$kind] . ' - ' : '') . 'News - trongate.cloud';
    $page_description = 'What changed on trongate.cloud, and what we learned building it.';
    require APPPATH . 'modules/templates/views/_site_head.php';
    ?>
</head>

<body class="lp">
    <?php require APPPATH . 'modules/templates/views/_site_header.php'; ?>

    <main class="lp-wrap lp-news">
        <div class="lp-news-head">
            <span class="lp-kicker">News</span>
            <h1 class="lp-h2">What's new on trongate.cloud.</h1>
            <p class="lp-sub">The changelog, and longer writing about running Trongate apps.</p>
        </div>

        <div class="lp-news-grid">
            <div>
                <nav class="lp-news-tabs" aria-label="Kinds of posts">
                    <a href="news"<?= $kind === null ? ' aria-current="page"' : '' ?>>All</a>
                    <?php foreach ($kinds as $key => $label): ?>
                        <a href="news/<?= $key ?>"<?= $kind === $key ? ' aria-current="page"' : '' ?>><?= $label ?></a>
                    <?php endforeach; ?>
                </nav>

                <?php if (empty($posts)): ?>
                    <p class="lp-news-empty">Nothing here yet.</p>
                <?php else: ?>
                    <ol class="lp-news-list">
                        <?php foreach ($posts as $post): ?>
                            <li>
                                <div class="lp-news-meta">
                                    <time datetime="<?= $post->date ?>"><?= $date($post->date) ?></time>
                                    <span class="lp-news-kind lp-news-kind-<?= $post->kind ?>"><?= $post->kind_label ?></span>
                                </div>
                                <h2><a href="news/<?= $post->slug ?>"><?= out($post->title) ?></a></h2>
                                <?php if ($post->summary !== ''): ?>
                                    <p><?= out($post->summary) ?></p>
                                <?php endif; ?>
                                <a href="news/<?= $post->slug ?>" class="lp-link">Read <?= $post->kind === 'writing' ? 'post' : 'entry' ?> &rarr;</a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>

            <aside class="lp-news-aside">
                <div>
                    <strong>Follow along</strong>
                    <p>New posts show up in any feed reader.</p>
                    <a href="news/feed" class="lp-link">Atom feed &rarr;</a>
                </div>
                <div>
                    <strong>From the Trongate project</strong>
                    <p>Framework news comes from the people who make it.</p>
                    <a href="https://trongate.io/essays" target="_blank" rel="noopener" class="lp-link">Trongate essays &rarr;</a>
                    <a href="https://github.com/trongate/trongate-framework/releases" target="_blank" rel="noopener" class="lp-link">Framework releases &rarr;</a>
                </div>
            </aside>
        </div>
    </main>

    <?php require APPPATH . 'modules/templates/views/_site_footer.php'; ?>
</body>

</html>
