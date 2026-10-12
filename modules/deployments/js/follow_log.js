// Deployment page (views/show.php): open the build log at its newest line and
// keep following new lines while the reader is at the bottom. Scrolling up to
// read stops the follow; scrolling back to the bottom resumes it.
document.addEventListener('DOMContentLoaded', function () {
    const box = document.getElementById('log-container');
    const lines = document.getElementById('logs-content');
    if (!box || !lines) return;

    let following = true;

    function scrollToBottom() {
        box.scrollTop = box.scrollHeight;
    }

    function rememberWhetherAtBottom() {
        following = box.scrollTop + box.clientHeight >= box.scrollHeight - 24;
    }

    function followNewLines() {
        if (following) scrollToBottom();
    }

    box.addEventListener('scroll', rememberWhetherAtBottom);
    new MutationObserver(followNewLines).observe(lines, { childList: true });

    scrollToBottom();
});
