// Database tab: Copy buttons (data-copy holds the text). The Credentials
// dialog is loaded into an MX modal after the page, and Show swaps in the
// password's own Copy button, so the clicks are handled on the document.
// The SQL Result panels use them too (views/_sql.php).
document.addEventListener('DOMContentLoaded', function () {
    if (!navigator.clipboard) return;

    document.body.classList.add('has-clipboard');

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.db-copy[data-copy]');
        if (!button) return;

        const label = button.dataset.label || button.textContent;
        button.dataset.label = label;

        navigator.clipboard.writeText(button.dataset.copy).then(function () {
            button.textContent = 'Copied';
        }, function () {
            button.textContent = 'Could not copy';
        }).then(function () {
            setTimeout(function () { button.textContent = label; }, 2000);
        });
    });
});
