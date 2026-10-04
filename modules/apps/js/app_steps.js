// New-app form shown one question at a time (views/_app_steps.php).
// Without JS every question stays visible and the form submits as one page.
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form[data-steps]');
    if (!form) return;

    const steps = Array.from(form.querySelectorAll('.wz-step'));
    const next = form.querySelector('[data-next]');
    const back = form.querySelector('[data-back]');
    const offset = parseInt(form.dataset.offset || '0', 10);
    const total = parseInt(form.dataset.total || String(steps.length), 10);
    const counter = document.getElementById('wz-counter');
    const segments = Array.from(document.querySelectorAll('#wz-progress span'));
    const label = form.querySelector('[name="label"]');
    const branch = form.querySelector('[name="repository_branch"]');
    let current = 0;

    form.classList.add('wz-js');

    const slugFor = function (text) {
        return text.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 32).replace(/-+$/, '');
    };

    const checked = function (name) {
        const input = form.querySelector('[name="' + name + '"]:checked');
        return input ? input.value : '';
    };

    const fieldsValid = function (step) {
        const fields = Array.from(step.querySelectorAll('input, select')).filter(function (field) {
            return !field.closest('[hidden]') && field.type !== 'hidden' && field.type !== 'search';
        });
        for (const field of fields) {
            if (!field.checkValidity()) {
                field.reportValidity();
                return false;
            }
        }
        return true;
    };

    const show = function (index) {
        current = Math.max(0, Math.min(index, steps.length - 1));
        steps.forEach(function (step, i) {
            step.classList.toggle('is-active', i === current);
        });

        const question = offset + current + 1;
        if (counter) counter.textContent = 'Question ' + question + ' of ' + total;
        segments.forEach(function (segment, i) {
            segment.className = i + 1 < question ? 'done' : (i + 1 === question ? 'active' : '');
        });

        back.hidden = current === 0;
        const isLast = current === steps.length - 1;
        next.textContent = isLast ? 'Launch ' + (label.value.trim() || 'your app') : 'OK';

        const focusable = steps[current].querySelector('input:checked, input:not([type=hidden]):not([type=checkbox]), select');
        if (focusable) focusable.focus({ preventScroll: true });
    };

    const advance = function () {
        if (!fieldsValid(steps[current])) return;
        if (current < steps.length - 1) {
            show(current + 1);
            return;
        }
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(next);
        } else {
            form.submit();
        }
    };

    next.addEventListener('click', function (event) {
        if (current < steps.length - 1) {
            event.preventDefault();
            advance();
        }
    });

    back.addEventListener('click', function () {
        show(current - 1);
    });

    form.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.target.tagName === 'TEXTAREA' || event.target.tagName === 'BUTTON') return;
        event.preventDefault();
        advance();
    });

    form.addEventListener('submit', function () {
        next.disabled = true;
        next.insertAdjacentHTML('afterbegin', '<span class="wz-spinner" aria-hidden="true"></span>');
    });

    // Repository: load its branches, suggest a name, move on
    const loadBranches = function (repository) {
        const url = form.dataset.branches + '?repository=' + encodeURIComponent(repository) + '&provider=' + encodeURIComponent(form.dataset.provider);
        branch.innerHTML = '<option value="">Loading branches...</option>';
        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            })
            .then(function (html) {
                branch.innerHTML = html;
                const names = Array.from(branch.options).map(function (option) { return option.value; });
                const preferred = ['main', 'master'].find(function (name) { return names.includes(name); });
                if (preferred) branch.value = preferred;
                updateSheet();
            })
            .catch(function () {
                branch.innerHTML = '<option value="">Could not load branches, pick the repository again</option>';
            });
    };

    form.querySelectorAll('[name="repository"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            loadBranches(radio.value);
            if (label.value.trim() === '') {
                const name = radio.value.split('/').pop().replace(/[-_]+/g, ' ');
                label.value = name.charAt(0).toUpperCase() + name.slice(1);
            }
            updateSheet();
            setTimeout(function () { show(current + 1); }, 250);
        });
    });

    const filter = form.querySelector('[data-filter]');
    if (filter) {
        filter.addEventListener('input', function () {
            const term = filter.value.toLowerCase();
            form.querySelectorAll('[data-repositories] .wz-choice').forEach(function (choice) {
                choice.hidden = term !== '' && !choice.dataset.name.includes(term);
            });
        });
    }

    form.querySelectorAll('[data-reveal]').forEach(function (button) {
        button.addEventListener('click', function () {
            const target = document.getElementById(button.dataset.reveal);
            target.hidden = false;
            button.hidden = true;
            const input = target.querySelector('input');
            if (input) input.focus();
        });
    });

    // "Your app so far"
    const sheet = {};
    document.querySelectorAll('[data-show]').forEach(function (cell) {
        sheet[cell.dataset.show] = cell;
    });
    const slugTarget = form.querySelector('[data-slug]');

    const updateSheet = function () {
        const slug = slugFor(label.value);
        const domain = (form.querySelector('[name="domain"]').value || '').trim();
        const onPush = form.querySelector('input[type=checkbox][name="auto_deploy"]').checked;
        const typeInput = form.querySelector('[name="type"]:checked');

        slugTarget.textContent = slug || 'your-app';
        if (sheet.repository) sheet.repository.textContent = checked('repository');
        if (sheet.branch) sheet.branch.textContent = branch.value ? branch.value + (onPush ? ', on push' : ', manual') : '';
        if (sheet.address) sheet.address.textContent = slug ? slug + '.' + form.dataset.appsDomain : '';
        if (sheet.domain) sheet.domain.textContent = domain;
        if (sheet.type) sheet.type.textContent = typeInput ? typeInput.closest('.wz-choice').querySelector('strong').textContent : '';
        if (current === steps.length - 1) next.textContent = 'Launch ' + (label.value.trim() || 'your app');
    };

    form.addEventListener('input', updateSheet);
    form.addEventListener('change', updateSheet);

    // Reload after a failed submit: reload branches, then open the first question that needs attention
    const repository = checked('repository');
    if (repository && branch.options.length <= 1) loadBranches(repository);
    updateSheet();

    let start = 0;
    if (form.querySelector('.wz-errors')) {
        start = steps.findIndex(function (step) {
            return Array.from(step.querySelectorAll('input, select')).some(function (field) {
                return field.type !== 'hidden' && !field.checkValidity();
            });
        });
        if (start === -1) start = steps.findIndex(function (step) { return step.dataset.step === 'name'; });
    }
    show(start);
});
