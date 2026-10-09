<?php
/**
 * The captured messages, newest first (Mail::index and Mail::poll).
 * Expects: $app, $messages (Mail_model::messages), $open_id (the open message, or 0).
 */
$e ??= fn($value) => htmlspecialchars((string) $value);
?>
<?php if ($messages === []): ?>
    <p class="ov-empty muted">No mail yet. What your app sends shows up here within a few seconds.</p>
<?php else: ?>
    <ul class="mail-list">
        <?php foreach ($messages as $message): ?>
        <li>
            <a href="apps/<?= (int) $app->id ?>/mail?open=<?= (int) $message->id ?>"<?= (int) $message->id === $open_id ? ' aria-current="true"' : '' ?>>
                <span class="mail-list-top">
                    <span class="mail-list-to"><?= $e($message->recipients) ?></span>
                    <time datetime="<?= $e(date('c', strtotime((string) $message->created_at))) ?>"><?= $e(date('j M H:i', strtotime((string) $message->created_at))) ?></time>
                </span>
                <span class="mail-list-subject"><?= $message->subject !== '' ? $e($message->subject) : '<span class="muted">(no subject)</span>' ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
