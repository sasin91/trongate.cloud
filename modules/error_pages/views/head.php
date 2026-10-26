<?php
/** Shared <head> for the error pages: Trongate's own CSS, nothing from the app. */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?= htmlspecialchars($home, ENT_QUOTES, 'UTF-8') ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($report->title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="css/trongate.css">
    <style>
        .error-page { max-width: 36rem; margin: 12vh auto 0; padding: 0 16px; }
        .error-page .status { font-size: 3rem; font-weight: 700; opacity: .25; margin: 0; }
        .error-page h1 { margin-top: 0; }
        .error-page pre { white-space: pre-wrap; word-break: break-word; font-size: .8rem; text-align: left; padding: 1em; background: rgba(127,127,127,.12); border-radius: 6px; }
    </style>
</head>
