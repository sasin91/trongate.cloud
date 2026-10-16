// Deployment page: the Logs modal (views/_log_modal.php). Once MX has built
// it (mx-after-swap on the Logs button), the log opens at its newest line and
// keeps following new lines while the reader is at the bottom, and its
// poller starts while the deployment runs. Closing the modal stops polling.

function followDeployLog() {
    const box = document.querySelector('#log-modal .log-container');
    const lines = document.getElementById('logs-content');
    const poller = document.getElementById('log-modal-poller');

    if (!box || !lines) {
        return;
    }

    let following = true;

    function scrollToBottom() {
        box.scrollTop = box.scrollHeight;
    }

    function rememberWhetherAtBottom() {
        following = box.scrollTop + box.clientHeight >= box.scrollHeight - 24;
    }

    function followNewLines() {
        if (following) {
            scrollToBottom();
        }
    }

    box.addEventListener('scroll', rememberWhetherAtBottom);
    new MutationObserver(followNewLines).observe(lines, { childList: true });
    scrollToBottom();

    if (poller) {
        TrongateMX.startPolling(poller, '2s');
    }
}

function stopDeployLogPolling() {
    const poller = document.getElementById('log-modal-poller');

    if (poller) {
        TrongateMX.stopPolling(poller);
    }
}

document.addEventListener('modalClosed', stopDeployLogPolling);
