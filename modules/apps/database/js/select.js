// Database tab: tick rows in the grid to delete them together. The tick box
// in the header ticks every row on the page. Delete selected says how many
// it deletes and opens a Trongate MX modal (mx-get + mx-build-modal) that
// asks first; this keeps its mx-get URL's count up to date.
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form[data-delete-rows]');
    if (!form) return;

    const button = form.querySelector('[data-delete-selected]');
    const tickAll = form.querySelector('[data-tick-all]');
    const rowTicks = form.querySelectorAll('[data-tick-row]');

    const showTicked = function () {
        const count = form.querySelectorAll('[data-tick-row]:checked').length;

        button.disabled = count === 0;
        button.textContent = 'Delete selected';
        if (count === 1) button.textContent = 'Delete 1 row';
        if (count > 1) button.textContent = 'Delete ' + count + ' rows';
        button.setAttribute('mx-get', button.dataset.confirmUrl + '&count=' + count);

        tickAll.checked = count > 0 && count === rowTicks.length;
        tickAll.indeterminate = count > 0 && count < rowTicks.length;
    };

    tickAll.addEventListener('change', function () {
        rowTicks.forEach(function (tick) {
            tick.checked = tickAll.checked;
        });
        showTicked();
    });

    rowTicks.forEach(function (tick) {
        tick.addEventListener('change', showTicked);
    });

    showTicked();
});
