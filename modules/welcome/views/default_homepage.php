<?php
$icon = fn(string $paths, int $size = 24, string $stroke = 'currentColor') => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="' . $stroke . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';

$values = [
    ['Simple', 'Connect GitHub and pick a repo. No servers to run, nothing to configure.', '<circle cx="12" cy="12" r="3.5"/><path d="M3 12h5.5"/><path d="M15.5 12H21"/>'],
    ['Fast', 'Every push goes live by itself, on HTTPS, with a database ready.', '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>'],
    ['Convenient', 'Free to start, hosted in Germany, and one click to roll back.', '<path d="M12 3 4.5 6v6c0 4.5 3.2 7.6 7.5 9 4.3-1.4 7.5-4.5 7.5-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>'],
];

$steps = [
    ['Connect GitHub', 'Link your GitHub account. Private repositories work.'],
    ['Pick a repository', 'Choose the repo and the branch that should go live.'],
    ['Name it', 'The name becomes your address at name.' . PLATFORM_APPS_DOMAIN . '. Bring your own domain whenever you like.'],
    ['Push', 'Each push builds, passes a health check and goes live. Roll back in a click if needed.'],
];

$stability = [
    ['Nothing goes live untested', 'A new build only takes traffic once your app answers. Until then, the last good one keeps serving.'],
    ['Failed deploys undo themselves', 'If a new build fails its check, we put the last good one back automatically. Nobody has to wake up for it.'],
    ['Every good build is kept', 'Roll back to any earlier release in one click, without rebuilding.'],
    ['No surprise upgrades', 'We build exactly what you pushed, on the Trongate version you chose. Your app changes when you change it.'],
];

$features = [
    ['Deploy on push', 'A webhook ships every push to your branch. If GitHub drops one, we notice the new commit and deploy it anyway.', '<path d="M12 16V8"/><path d="M8.5 11.5 12 8l3.5 3.5"/><path d="M7 19a4.5 4.5 0 0 1-1-8.9A5.5 5.5 0 0 1 16.6 8.5 3.5 3.5 0 0 1 18 19z"/>'],
    ['Custom domains, HTTPS included', 'Add a CNAME. We spot the DNS change, issue a certificate and switch your domain on, no redeploy needed.', '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'],
    ['Safe rollouts', 'Your old version serves until the new one passes its check. If it fails, the last good build comes straight back. Roll back any time in one click.', '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>'],
    ['A database per app', 'MariaDB with the credentials wired into your config.', '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>'],
    ['Sleeps when idle', 'Side projects nap when nobody visits and wake on the next request. Keep production always on.', '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>'],
    ['Status at a glance', 'See whether your app is up, and every deployment with its build log.', '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>'],
];

$faq = [
    ['Which Trongate versions work?', 'Trongate v1 and v2. You pick the version when you set up the app.'],
    ['Do I need Docker or a Dockerfile?', 'No. We build the container image from your repository for you.'],
    ['What does it cost?', "Nothing while we're in early access."],
    ['How do I use my own domain?', 'Add it to your app and create a CNAME record pointing at ' . PLATFORM_APPS_DOMAIN . '. We check the DNS and switch it on with a certificate as soon as it resolves.'],
    ['Where is my app hosted?', 'On Hetzner servers in Falkenstein, Germany.'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    $page_title = "trongate.cloud - push your Trongate app, it's live";
    $page_description = 'Hosting for apps built with the Trongate PHP framework. You push, we deploy: simple, fast, convenient.';
    require APPPATH . 'modules/templates/views/_site_head.php';
    ?>
</head>

<body class="lp">
    <?php require APPPATH . 'modules/templates/views/_site_header.php'; ?>

    <main>
        <section class="lp-hero" id="top">
            <div class="lp-hero-copy">
                <a href="#faq" class="lp-pill"><span>Early access</span> Free while we grow</a>
                <h1 class="lp-h1">Push your Trongate app.<br><em>It's live.</em></h1>
                <p class="lp-lead">Connect GitHub, answer a few quick questions and your app runs on its own HTTPS address with a database. Every push after that ships by itself.</p>
                <div class="lp-hero-actions">
                    <a href="register" class="lp-btn lp-btn-lg">Deploy your first app</a>
                    <a href="#how" class="lp-more">See how it works <span aria-hidden="true">&rarr;</span></a>
                </div>
            </div>

            <div class="lp-shot">
                <div class="lp-shot-tags" aria-hidden="true">
                    <span class="lp-tag lp-mono"><b>$</b> git push origin main</span>
                    <span class="lp-tag"><i></i> 4f2b8d1 is live</span>
                </div>
                <div class="lp-frame">
                    <div class="lp-frame-inner" aria-hidden="true">
                        <iframe src="<?= BASE_URL ?>welcome/preview" title="App overview" tabindex="-1" loading="lazy" scrolling="no"></iframe>
                    </div>
                </div>
            </div>
        </section>

        <section class="lp-band">
            <div class="lp-wrap lp-values">
                <div class="lp-center">
                    <h2 class="lp-h2 lp-h2-xl">You push. <em>We deploy.</em></h2>
                    <p class="lp-sub">That's the whole job.</p>
                </div>
                <div class="lp-3">
                    <?php foreach ($values as [$title, $text, $paths]): ?>
                        <div class="lp-value">
                            <span class="lp-value-icon"><?= $icon($paths) ?></span>
                            <div>
                                <h3><?= $title ?></h3>
                                <p><?= $text ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="lp-wrap lp-section" id="how">
            <div class="lp-head">
                <span class="lp-kicker">How it works</span>
                <h2 class="lp-h2">Four answers. Then it's live.</h2>
            </div>
            <ol class="lp-4">
                <?php foreach ($steps as $i => [$title, $text]): ?>
                    <li class="lp-step">
                        <span><?= sprintf('%02d', $i + 1) ?></span>
                        <h3><?= $title ?></h3>
                        <p><?= htmlspecialchars($text) ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section class="lp-wrap lp-stable-wrap" id="stability">
            <div class="lp-stable">
                <div class="lp-stable-copy">
                    <span class="lp-kicker">Stability</span>
                    <h2 class="lp-h2">Peace of mind from deploy to production.</h2>
                    <p class="lp-sub">Stability throughout, the Trongate way. The framework puts stability first, and so does the place you run it.</p>
                    <a href="https://trongate.io/essays/trongates-new-versioning-system" target="_blank" rel="noopener" class="lp-link">Read Trongate's commitment to stability &rarr;</a>
                </div>
                <ol class="lp-stable-list">
                    <?php foreach ($stability as $i => [$title, $text]): ?>
                        <li>
                            <span><?= sprintf('%02d', $i + 1) ?></span>
                            <div>
                                <strong><?= $title ?></strong>
                                <p><?= $text ?></p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </section>

        <section class="lp-band" id="features">
            <div class="lp-wrap lp-section">
                <div class="lp-head">
                    <span class="lp-kicker">Features</span>
                    <h2 class="lp-h2">Everything between git push and a happy visitor.</h2>
                </div>
                <dl class="lp-2">
                    <?php foreach ($features as [$title, $text, $paths]): ?>
                        <div class="lp-feature">
                            <span class="lp-feature-icon"><?= $icon($paths, 20, '#FFFFFF') ?></span>
                            <div>
                                <dt><?= $title ?></dt>
                                <dd><?= $text ?></dd>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </div>
        </section>

        <section class="lp-wrap lp-section" id="faq">
            <h2 class="lp-h2">Questions</h2>
            <dl class="lp-faqs">
                <?php foreach ($faq as [$question, $answer]): ?>
                    <div class="lp-faq">
                        <dt><?= $question ?></dt>
                        <dd><?= htmlspecialchars($answer) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    </main>

    <?php require APPPATH . 'modules/templates/views/_site_footer.php'; ?>

    <script>
        // Scale the 1440px-wide dashboard shot to fit its frame
        (() => {
            const frame = document.querySelector('.lp-frame-inner');
            if (!frame || !('ResizeObserver' in window)) return;
            new ResizeObserver(([entry]) => {
                frame.style.setProperty('--shot-scale', entry.contentRect.width / 1440);
            }).observe(frame);
        })();
    </script>
</body>

</html>
