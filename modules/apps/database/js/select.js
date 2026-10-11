// Database tab: tick rows in the grid to delete them together. The tick box
// in the header ticks every row on the page. Delete selected says how many
// it deletes, and asks before the form is sent.
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form[data-delete-rows]');
    if (!form) return;

    const button = form.querySelector('[data-delete-selected]');
    const tickAll = form.querySelector('[data-tick-all]');
    const rowTicks = form.querySelectorAll('[data-tick-row]');

    const tickedCount = function () {
        return form.querySelectorAll('[data-tick-row]:checked').length;
    };

    const rowsText = function (count) {
        if (count === 1) return '1 row';
        return count + ' rows';
    };

    const showTicked = function () {
        const count = tickedCount();

        button.disabled = count === 0;
        button.textContent = count === 0 ? 'Delete selected' : 'Delete ' + rowsText(count);
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

    form.addEventListener('submit', function (event) {
        const rows = rowsText(tickedCount());
        let question = 'Delete ' + rows + ' without a backup? This cannot be undone.';

        if (form.backup.checked) {
            question = 'Delete ' + rows + '? They are backed up first, so you can restore them under Backups.';
        }

        if (!confirm(question)) event.preventDefault();
    });

    showTicked();
});
