// Single-question pages: show a spinner on the button while the form submits.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form.wz-stage:not([data-steps])').forEach(function (form) {
        form.addEventListener('submit', function () {
            const button = form.querySelector('.wz-ok');
            if (!button) return;
            button.disabled = true;
            button.insertAdjacentHTML('afterbegin', '<span class="wz-spinner" aria-hidden="true"></span>');
        });
    });
});
