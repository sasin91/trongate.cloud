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

    new MutationObserver(function () {
        const all = lines.querySelectorAll('.app-log-line');
        for (let i = 0; i < all.length - MAX_LINES; i++) {
            all[i].remove();
        }
        if (following) toBottom();
    }).observe(lines, { childList: true, subtree: true });

    toBottom();
});
