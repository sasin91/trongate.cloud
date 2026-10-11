// Database tab: right-click (long-press on Android) a password cell in the
// rows grid for a small menu. Its Change password item is a Trongate MX
// trigger (mx-get + mx-build-modal) that loads views/_password_form.php into
// a modal; the row form's button is the same trigger without this menu.
document.addEventListener('DOMContentLoaded', function () {
    if (!document.querySelector('td[data-password-form]')) return;

    const menu = document.createElement('div');
    menu.className = 'db-menu';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    document.body.appendChild(menu);

    const closeMenu = function () {
        menu.hidden = true;
    };

    document.addEventListener('contextmenu', function (event) {
        const cell = event.target.closest('td[data-password-form]');
        if (!cell) {
            closeMenu();
            return;
        }

        event.preventDefault();
        menu.replaceChildren();

        const change = document.createElement('button');
        change.type = 'button';
        change.textContent = 'Change password…';
        change.setAttribute('role', 'menuitem');
        change.setAttribute('mx-get', cell.dataset.passwordForm);
        change.setAttribute('mx-build-modal', cell.closest('table').dataset.passwordModal);
        menu.appendChild(change);

        const edit = cell.parentElement.querySelector('[data-edit-row]');
        if (edit) {
            const editItem = document.createElement('button');
            editItem.type = 'button';
            editItem.textContent = 'Edit row';
            editItem.setAttribute('role', 'menuitem');
            editItem.setAttribute('mx-get', edit.getAttribute('mx-get'));
            editItem.setAttribute('mx-build-modal', edit.getAttribute('mx-build-modal'));
            menu.appendChild(editItem);
        }

        menu.hidden = false;
        menu.style.left = Math.min(event.clientX, window.innerWidth - menu.offsetWidth - 8) + 'px';
        menu.style.top = Math.min(event.clientY, window.innerHeight - menu.offsetHeight - 8) + 'px';
        change.focus();
    });

    // MX handles the click on the menu item first (its listener is on <body>)
    document.addEventListener('click', closeMenu);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeMenu();
    });
    window.addEventListener('scroll', closeMenu, true);
});
