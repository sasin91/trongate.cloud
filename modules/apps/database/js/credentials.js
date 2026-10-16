// Database tab: Copy buttons in the Credentials dialog (views/_credentials.php).
// The dialog is loaded into an MX modal after the page, and Show swaps in the
// password's own Copy button, so the clicks are handled on the document.
document.addEventListener('DOMContentLoaded', function () {
    if (!navigator.clipboard) return;

    document.body.classList.add('has-clipboard');

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.db-copy[data-copy]');
        if (!button) return;

        navigator.clipboard.writeText(button.dataset.copy).then(function () {
            button.textContent = 'Copied';
        }, function () {
            button.textContent = 'Could not copy';
        }).then(function () {
            setTimeout(function () { button.textContent = 'Copy'; }, 2000);
        });
    });
});
