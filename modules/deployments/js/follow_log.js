// Deployment page (views/_steps.php): each step's log opens at its newest
// line and keeps following new lines while the reader is at its bottom.
// Scrolling up to read stops the follow; scrolling back down resumes it.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.step-logs').forEach(follow);

    function follow(box) {
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
        new MutationObserver(followNewLines).observe(box, { childList: true });

        scrollToBottom();
    }
});
