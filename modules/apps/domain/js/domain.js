// Domain tab (apps/domain/views): Copy buttons next to each DNS record's name
// and content. The records table is replaced once its lookups finish, so the
// clicks are handled on the document.
document.addEventListener('DOMContentLoaded', function () {
    if (!navigator.clipboard) return;

    document.body.classList.add('has-clipboard');

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.dns-copy');
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
