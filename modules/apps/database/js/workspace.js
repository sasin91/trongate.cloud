// Database tab workspace: the sidebar's search box hides tables whose name
// does not contain what is typed, and double-clicking a row in the grid opens
// its Edit row modal, like clicking its row number.
(function () {
    function filterTables(search) {
        const typed = search.value.trim().toLowerCase();
        const items = document.querySelectorAll('[data-table-name]');

        items.forEach(function (item) {
            const name = item.dataset.tableName.toLowerCase();
            item.hidden = typed !== '' && !name.includes(typed);
        });
    }

    function openRowOnDoubleClick(event) {
        if (event.target.closest('a, button, input')) {
            return;
        }

        const row = event.target.closest('.db-grid tbody tr');
        if (!row) {
            return;
        }

        const edit = row.querySelector('[data-edit-row]');
        if (edit) {
            edit.click();
        }
    }

    const search = document.querySelector('[data-table-search]');
    if (search) {
        search.addEventListener('input', function () {
            filterTables(search);
        });
    }

    document.addEventListener('dblclick', openRowOnDoubleClick);
})();
