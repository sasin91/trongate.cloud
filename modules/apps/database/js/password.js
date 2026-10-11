// Database tab: Change password (views/_password_dialog.php). Right-click a
// password cell in the rows grid for a small menu, or press the button next
// to a password field in the row form; both open the dialog for that cell.
document.addEventListener('DOMContentLoaded', function () {
    const dialog = document.getElementById('db-password');
    if (!dialog) return;

    const form = dialog.querySelector('form');
    const menu = document.createElement('div');
    menu.className = 'db-menu';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    document.body.appendChild(menu);

    const text = function (tag, value, className) {
        const node = document.createElement(tag);
        node.textContent = value;
        if (className) node.className = className;
        return node;
    };

    const open = function (opener) {
        const key = JSON.parse(opener.dataset.key);
        const hash = JSON.parse(opener.dataset.hash);
        const column = opener.dataset.passwordColumn;

        form.reset();
        form.password.type = form.password_confirm.type = 'password';
        form.column.value = column;

        const keyHolder = form.querySelector('[data-password-key]');
        keyHolder.replaceChildren();
        Object.keys(key).forEach(function (name) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'key[' + name + ']';
            input.value = key[name];
            keyHolder.appendChild(input);
        });

        form.querySelector('[data-password-where]').textContent = form.table.value + ' row '
            + Object.keys(key).map(function (name) { return name + ' = ' + key[name]; }).join(', ') + ', column ' + column;
        form.querySelector('[data-password-current]').textContent = hash.current;

        const note = form.querySelector('[data-password-note]');
        note.textContent = hash.note || '';
        note.hidden = !hash.note;

        form.algorithm.replaceChildren();
        Object.keys(hash.choices).forEach(function (value) {
            const option = text('option', hash.choices[value]);
            option.value = value;
            option.selected = value === hash.default;
            form.algorithm.appendChild(option);
        });

        dialog.showModal();
        form.password.focus();
    };

    const closeMenu = function () {
        menu.hidden = true;
    };

    // The row form's buttons
    document.querySelectorAll('button[data-password-column]').forEach(function (button) {
        button.addEventListener('click', function () { open(button); });
    });

    // The rows grid: right-click (long-press on Android) a password cell
    document.addEventListener('contextmenu', function (event) {
        const cell = event.target.closest('td[data-password-column]');
        if (!cell) {
            closeMenu();
            return;
        }

        event.preventDefault();
        menu.replaceChildren();

        const change = text('button', 'Change password…');
        change.type = 'button';
        change.setAttribute('role', 'menuitem');
        change.addEventListener('click', function () {
            closeMenu();
            open(cell);
        });
        menu.appendChild(change);

        const edit = cell.parentElement.querySelector('a[data-edit-row]');
        if (edit) {
            const link = text('a', 'Edit row');
            link.href = edit.href;
            link.setAttribute('role', 'menuitem');
            menu.appendChild(link);
        }

        menu.hidden = false;
        menu.style.left = Math.min(event.clientX, window.innerWidth - menu.offsetWidth - 8) + 'px';
        menu.style.top = Math.min(event.clientY, window.innerHeight - menu.offsetHeight - 8) + 'px';
        change.focus();
    });

    document.addEventListener('click', function (event) {
        if (!menu.contains(event.target)) closeMenu();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeMenu();
    });
    window.addEventListener('scroll', closeMenu, true);

    dialog.querySelector('[data-password-cancel]').addEventListener('click', function () {
        dialog.close();
    });
    // Do not keep a typed password around once the dialog is closed
    dialog.addEventListener('close', function () {
        form.password.value = form.password_confirm.value = '';
    });
});
