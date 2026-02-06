document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        const submitButton = this.querySelector('button[type="submit"]');

        submitButton.classList.add('btn-loading');
        submitButton.disabled = true;

        const spinner = submitButton.querySelector('.spinner');
        spinner.style.display = 'inline-block';
    });
});