<?php
// The public site's <head> contents (landing page and news). Expects $page_title and $page_description.
?>
<base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= out($page_title) ?></title>
    <meta name="description" content="<?= out($page_description) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@800;900&family=Geist:wght@400;500;600&family=Geist+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="<?= BASE_URL ?>css/landing.css">
    <link rel="alternate" type="application/atom+xml" title="trongate.cloud news" href="<?= BASE_URL ?>news/feed">
