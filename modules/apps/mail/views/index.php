<?php
/**
 * apps/{id}/mail (Mail::index). Expects: $app, $urls, $live_state, $can_rollback,
 * $capture_on (Runtime_settings::captures_mail), $messages (Mail_model::messages),
 * $open (the message ?open= names, with its source, or false), $parsed (Mail_message::parse of it, or null).
 */
$e = fn($value) => htmlspecialchars((string) $value);
$base = 'apps/' . (int) $app->id . '/mail';
$open_id = $open !== false ? (int) $open->id : 0;
?>
<div class="ov">
    <?php $active_tab = 'mail'; require APPPATH . 'modules/apps/views/_app_header.php'; ?>

    <?= flashdata('<p class="flash">', '</p>') ?>

    <?php if (!$capture_on): ?>
        <p class="mail-note">Mail capture is off, so your app sends with the SMTP settings in its <code>config/trongate_email.php</code> and new mail does not show up here. Turn it back on in <a href="apps/<?= (int) $app->id ?>/settings">Settings</a>.</p>
    <?php endif; ?>

    <div class="mail-main">
        <section class="ov-card mail-inbox" aria-labelledby="mail-inbox-h">
            <div class="ov-card-head">
                <h2 id="mail-inbox-h">Inbox</h2>
                <?php if ($messages !== []): ?>
                    <?= form_open($base . '/delete', ['onsubmit' => "return confirm('Delete all captured mail of this app?')"]) ?>
                        <input type="hidden" name="all" value="1">
                        <button type="submit" class="btn-link">Delete all</button>
                    <?= form_close() ?>
                <?php endif; ?>
            </div>
            <div id="mail-list" mx-get="<?= $e($base . '/poll?open=' . $open_id) ?>" mx-trigger="every 5s">
                <?php require __DIR__ . '/_list.php'; ?>
            </div>
        </section>

        <section class="ov-card mail-message" aria-labelledby="mail-message-h">
            <?php if ($open === false): ?>
                <div class="ov-card-head">
                    <h2 id="mail-message-h">Mail from your app</h2>
                </div>
                <div class="ov-pad">
                    <p>Mail your app sends with Trongate's email module is caught here instead of going out, so you can see exactly what your users would get. Nothing is delivered.</p>
                    <pre class="mail-code"><code>$this->module('trongate_email');
$this->trongate_email->send([
    'to_email' => 'someone@example.com',
    'subject' => 'Welcome',
    'body_html' => '&lt;p&gt;Hello!&lt;/p&gt;',
]);</code></pre>
                    <p class="muted">We set the SMTP settings in <code>config/trongate_email.php</code> on every deploy; your own <code>smtp_user</code> stays as the From address. To send real mail through your own provider, turn mail capture off in <a href="apps/<?= (int) $app->id ?>/settings">Settings</a>. Mail is kept for <?= Mail_capture::KEEP_DAYS ?> days, up to <?= Mail_capture::KEEP_PER_APP ?> messages.</p>
                </div>
            <?php else: ?>
                <div class="ov-card-head">
                    <h2 id="mail-message-h"><?= $parsed['subject'] !== '' ? $e($parsed['subject']) : '(no subject)' ?></h2>
                    <span class="mail-actions">
                        <a href="<?= $e($base . '/source?id=' . $open_id) ?>">Download .eml</a>
                        <?= form_open($base . '/delete') ?>
                            <input type="hidden" name="mail_id" value="<?= $open_id ?>">
                            <button type="submit" class="btn-link">Delete</button>
                        <?= form_close() ?>
                    </span>
                </div>
                <dl class="mail-headers">
                    <dt>From</dt>
                    <dd><?= $e($parsed['from'] !== '' ? $parsed['from'] : $open->mail_from) ?></dd>
                    <dt>To</dt>
                    <dd><?= $e($open->recipients) ?></dd>
                    <?php if ($parsed['cc'] !== ''): ?>
                        <dt>Cc</dt>
                        <dd><?= $e($parsed['cc']) ?></dd>
                    <?php endif; ?>
                    <?php if ($parsed['reply_to'] !== ''): ?>
                        <dt>Reply-To</dt>
                        <dd><?= $e($parsed['reply_to']) ?></dd>
                    <?php endif; ?>
                    <dt>Sent</dt>
                    <dd><?= $e(date('j M Y H:i:s', strtotime((string) $open->created_at))) ?> UTC &middot; <?= number_format((int) $open->bytes / 1024, 1) ?> KB</dd>
                    <?php if ($parsed['attachments'] !== []): ?>
                        <dt>Attachments</dt>
                        <dd><?= $e(implode(', ', $parsed['attachments'])) ?></dd>
                    <?php endif; ?>
                </dl>

                <?php if ($parsed['html'] !== ''): ?>
                    <!-- A sandbox without allow-* flags: no scripts, forms or same-origin access -->
                    <iframe class="mail-html" sandbox referrerpolicy="no-referrer" title="HTML version" srcdoc="<?= $e($parsed['html']) ?>"></iframe>
                <?php endif; ?>

                <?php if ($parsed['text'] !== ''): ?>
                    <details class="mail-text"<?= $parsed['html'] === '' ? ' open' : '' ?>>
                        <summary>Plain text version</summary>
                        <pre><?= $e($parsed['text']) ?></pre>
                    </details>
                <?php endif; ?>

                <details class="mail-text">
                    <summary>Source</summary>
                    <pre><?= $e(mb_strimwidth(mb_scrub((string) $open->raw, 'UTF-8'), 0, 200000, "\n…")) ?></pre>
                </details>
            <?php endif; ?>
        </section>
    </div>
</div>
