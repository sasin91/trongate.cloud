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
    const publicLink = form.querySelector('[name="public_link"]');
    const radios = Array.from(form.querySelectorAll('[name="repository"]'));
    let current = 0;
    let branchesLoadedFor = '';

    form.classList.add('wz-js');

    const slugFor = function (text) {
        return text.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 32).replace(/-+$/, '');
    };

    const checked = function (name) {
        const input = form.querySelector('[name="' + name + '"]:checked');
        return input ? input.value : '';
    };

    // Where the app comes from: the pasted public link, else the picked repository
    const source = function () {
        const link = publicLink.value.trim();
        return link !== '' ? link : checked('repository');
    };

    const sourceQuery = function () {
        const link = publicLink.value.trim();
        if (link !== '') return 'public_link=' + encodeURIComponent(link);
        return 'repository=' + encodeURIComponent(checked('repository')) + '&provider=' + encodeURIComponent(form.dataset.provider);
    };

    // The repository's name: from github.com/owner/name/..., or the picked owner/name
    const nameFromSource = function (value) {
        const link = value.match(/github\.com\/[^\/]+\/([^\/?#]+)/i);
        const name = link ? link[1] : value.split('/').pop();
        return name.replace(/\.git$/i, '');
    };

    const suggestLabel = function () {
        if (label.value.trim() !== '') return;
        const name = nameFromSource(source()).replace(/[-_]+/g, ' ');
        label.value = name.charAt(0).toUpperCase() + name.slice(1);
    };

    // A pasted link replaces the picked repository and deploys only by hand
    const applyPublicLink = function () {
        const isPublic = publicLink.value.trim() !== '';
        radios.forEach(function (radio) {
            radio.required = !isPublic;
            if (isPublic) radio.checked = false;
        });
        form.querySelectorAll('[data-push-row]').forEach(function (row) { row.hidden = isPublic; });
        form.querySelectorAll('[data-public-only]').forEach(function (hint) { hint.hidden = !isPublic; });
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
        if (steps[current].dataset.step === 'repository' && publicLink.value.trim() !== '') {
            loadBranches();
            suggestLabel();
            updateSheet();
        }
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

    // Environment: the variables the branch's config/*.php reads, read again only when the branch changes
    const envFields = form.querySelector('[data-env-fields]');

    const loadEnv = function () {
        if (!source() || !branch.value) return;

        const loadedFor = source() + '@' + branch.value;
        if (envFields.dataset.loadedFor === loadedFor) return;
        envFields.dataset.loadedFor = loadedFor;

        const url = form.dataset.env + '?' + sourceQuery() + '&branch=' + encodeURIComponent(branch.value);
        envFields.innerHTML = '<p class="wz-hint">Reading config/ for environment variables...</p>';
        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            })
            .then(function (html) {
                if (envFields.dataset.loadedFor !== loadedFor) return;
                envFields.innerHTML = html;
                updateSheet();
            })
            .catch(function () {
                envFields.dataset.loadedFor = '';
                envFields.innerHTML = '<p class="wz-hint">Could not read config/. You can add variables later on the app\'s Settings page.</p>';
            });
    };

    branch.addEventListener('change', loadEnv);

    // Database: the branch's .sql files, read again only when the branch changes
    const sqlFiles = form.querySelector('[data-sql-files]');

    const loadSqlFiles = function () {
        if (!source() || !branch.value) return;

        const loadedFor = source() + '@' + branch.value;
        if (sqlFiles.dataset.loadedFor === loadedFor) return;
        sqlFiles.dataset.loadedFor = loadedFor;

        const url = form.dataset.sql + '?' + sourceQuery() + '&branch=' + encodeURIComponent(branch.value);
        sqlFiles.innerHTML = '<p class="wz-hint">Looking for .sql files...</p>';
        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            })
            .then(function (html) {
                if (sqlFiles.dataset.loadedFor !== loadedFor) return;
                sqlFiles.innerHTML = html;
                updateSheet();
            })
            .catch(function () {
                sqlFiles.dataset.loadedFor = '';
                sqlFiles.innerHTML = '<p class="wz-hint">Could not list the repository\'s files. Type a path below instead.</p>';
            });
    };

    branch.addEventListener('change', loadSqlFiles);

    // Repository: load its branches, suggest a name, move on
    const loadBranches = function () {
        const loadingFor = source();
        if (loadingFor === branchesLoadedFor) return;
        branchesLoadedFor = loadingFor;

        const url = form.dataset.branches + '?' + sourceQuery();
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
                loadEnv();
                loadSqlFiles();
                updateSheet();
            })
            .catch(function () {
                branchesLoadedFor = '';
                const message = publicLink.value.trim() !== ''
                    ? 'Could not read this link, check that the repository is public'
                    : 'Could not load branches, pick the repository again';
                branch.innerHTML = '<option value="">' + message + '</option>';
            });
    };

    radios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            publicLink.value = '';
            applyPublicLink();
            loadBranches();
            suggestLabel();
            updateSheet();
            setTimeout(function () { show(current + 1); }, 250);
        });
    });

    publicLink.addEventListener('input', applyPublicLink);

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
        if (sheet.repository) sheet.repository.textContent = source();
        if (sheet.branch) sheet.branch.textContent = branch.value ? branch.value + (onPush ? ', on push' : ', manual') : '';
        if (sheet.env) {
            const filled = Array.from(envFields.querySelectorAll('input')).filter(function (input) { return input.value !== ''; }).length;
            sheet.env.textContent = filled === 0 ? '' : filled + (filled === 1 ? ' variable' : ' variables');
        }
        if (sheet.database) {
            const files = sqlFiles.querySelectorAll('input[type=checkbox]:checked').length + (form.querySelector('[name="sql_path"]').value.trim() === '' ? 0 : 1);
            const parts = [];
            if (files > 0) parts.push(files + (files === 1 ? ' SQL file' : ' SQL files'));
            if (form.querySelector('[name="setup_sql"]').value.trim() !== '') parts.push('typed SQL');
            sheet.database.textContent = parts.join(' + ');
        }
        if (sheet.address) sheet.address.textContent = slug ? slug + '.' + form.dataset.appsDomain : '';
        if (sheet.domain) sheet.domain.textContent = domain;
        if (sheet.type) sheet.type.textContent = typeInput ? typeInput.closest('.wz-choice').querySelector('strong').textContent : '';
        if (current === steps.length - 1) next.textContent = 'Launch ' + (label.value.trim() || 'your app');
    };

    form.addEventListener('input', updateSheet);
    form.addEventListener('change', updateSheet);

    // Reload after a failed submit: reload branches, then open the first question that needs attention
    applyPublicLink();
    if (source() && branch.options.length <= 1) loadBranches();
    updateSheet();

    let start = 0;
    if (form.querySelector('.wz-errors') && form.dataset.errorStep) {
        start = steps.findIndex(function (step) { return step.dataset.step === form.dataset.errorStep; });
    } else if (form.querySelector('.wz-errors')) {
        start = steps.findIndex(function (step) {
            return Array.from(step.querySelectorAll('input, select')).some(function (field) {
                return field.type !== 'hidden' && !field.checkValidity();
            });
        });
        if (start === -1) start = steps.findIndex(function (step) { return step.dataset.step === 'name'; });
    }
    show(start);
});
