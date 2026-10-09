<?= '<?xml version="1.0" encoding="UTF-8"?>' . "\n" ?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <title>trongate.cloud news</title>
    <subtitle>What changed on trongate.cloud, and what we learned building it.</subtitle>
    <id><?= BASE_URL ?>news</id>
    <link rel="alternate" type="text/html" href="<?= BASE_URL ?>news"/>
    <link rel="self" type="application/atom+xml" href="<?= BASE_URL ?>news/feed"/>
    <updated><?= $posts ? $posts[0]->date : date('Y-m-d') ?>T00:00:00Z</updated>
    <author><name>trongate.cloud</name></author>
<?php foreach ($posts as $post): ?>
    <entry>
        <title><?= out($post->title, 'xml') ?></title>
        <id><?= BASE_URL ?>news/<?= $post->slug ?></id>
        <link rel="alternate" type="text/html" href="<?= BASE_URL ?>news/<?= $post->slug ?>"/>
        <published><?= $post->date ?>T00:00:00Z</published>
        <updated><?= $post->date ?>T00:00:00Z</updated>
        <category term="<?= $post->kind ?>" label="<?= $post->kind_label ?>"/>
        <summary><?= out($post->summary, 'xml') ?></summary>
        <content type="html"><?= out($post->html, 'xml') ?></content>
    </entry>
<?php endforeach; ?>
</feed>
