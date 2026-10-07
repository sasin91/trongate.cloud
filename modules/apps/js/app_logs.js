// Logs page (views/logs.php): keep following new lines while the reader is at
// the bottom, leave the scroll alone once they scroll up, and drop the oldest
// lines so a long-open tab stays light.
document.addEventListener('DOMContentLoaded', function () {
    const box = document.getElementById('app-log-box');
    const lines = document.getElementById('app-log-lines');
    if (!box || !lines) return;

    const MAX_LINES = 2000;
    let following = true;

    const toBottom = function () {
        box.scrollTop = box.scrollHeight;
    };

    box.addEventListener('scroll', function () {
        following = box.scrollTop + box.clientHeight >= box.scrollHeight - 24;
    });

    // "Copy last error": the newest error line plus the lines PHP writes
    // after it without a level of their own (Stack trace:, #0 ..., thrown in ...)
    const copyButton = document.getElementById('copy-last-error');
    const CONTINUATION = /^(Stack trace:|#\d+ |\{main\}|\s|thrown in |Next )/;

    const lastError = function () {
        const errors = lines.querySelectorAll('.app-log-line[data-level="error"]');
        const first = errors[errors.length - 1];
        if (!first) return null;

        const entry = [first];
        for (let line = first.nextElementSibling; line; line = line.nextElementSibling) {
            if (line.dataset.level || line.dataset.pod !== first.dataset.pod) break;
            if (!CONTINUATION.test(line.querySelector('.app-log-text').textContent)) break;
            entry.push(line);
        }

        return entry.map(function (line, i) {
            const text = line.querySelector('.app-log-text').textContent;
            return i === 0 ? '[' + line.dataset.time.slice(0, 19).replace('T', ' ') + ' UTC] ' + text : text;
        }).join('\n');
    };

    const updateCopyButton = function () {
        copyButton.disabled = lines.querySelector('.app-log-line[data-level="error"]') === null;
    };

    if (copyButton && navigator.clipboard) {
        copyButton.hidden = false;
        updateCopyButton();

        copyButton.addEventListener('click', function () {
            const text = lastError();
            if (text === null) return;

            navigator.clipboard.writeText(text).then(function () {
                copyButton.textContent = 'Copied';
            }, function () {
                copyButton.textContent = 'Could not copy';
            }).then(function () {
                setTimeout(function () { copyButton.textContent = 'Copy last error'; }, 2000);
            });
        });
    }

    new MutationObserver(function () {
        const all = lines.querySelectorAll('.app-log-line');
        for (let i = 0; i < all.length - MAX_LINES; i++) {
            all[i].remove();
        }
        if (copyButton && !copyButton.hidden) updateCopyButton();
        if (following) toBottom();
    }).observe(lines, { childList: true, subtree: true });

    toBottom();
});
