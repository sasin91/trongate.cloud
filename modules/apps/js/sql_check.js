// Live SQL check, like Laravel Precognition: while SQL is typed or pasted into a
// textarea with data-sql-check (the URL to post it to), the server runs the same
// checks the real submit runs, without running the SQL, and its answer
// (views/_sql_check.php) is shown in the element data-sql-check-result names.
// Without JS the SQL is still checked when it is submitted.
document.addEventListener('DOMContentLoaded', function () {
    const WAIT_MS = 500;

    document.querySelectorAll('textarea[data-sql-check]').forEach(function (textarea) {
        const result = document.getElementById(textarea.dataset.sqlCheckResult);
        const form = textarea.form;
        const allowChanges = form ? form.querySelector('[name="allow_changes"]') : null;
        let timer = null;
        let request = null;
        let checked = null;

        if (!result) return;

        const show = function (html) {
            result.innerHTML = html;
            result.hidden = html === '';
        };

        const check = function () {
            const sql = textarea.value.trim();
            const asked = sql + (allowChanges && allowChanges.checked ? '\n1' : '\n0');

            if (asked === checked) return;
            checked = asked;

            if (request) request.abort();

            if (sql === '') {
                show('');
                return;
            }

            const body = new FormData();
            body.append('sql', sql);

            if (allowChanges && allowChanges.checked) body.append('allow_changes', '1');

            const csrf = form ? form.querySelector('[name="csrf_token"]') : null;
            if (csrf) body.append('csrf_token', csrf.value);

            request = new AbortController();
            fetch(textarea.dataset.sqlCheck, { method: 'POST', body: body, credentials: 'same-origin', signal: request.signal })
                .then(function (response) {
                    // 422 is an answer too: the SQL would be refused
                    if (!response.ok && response.status !== 422) throw new Error('HTTP ' + response.status);
                    return response.text();
                })
                .then(show)
                .catch(function (error) {
                    if (error.name === 'AbortError') return;
                    checked = null;
                    show('');
                });
        };

        const later = function () {
            clearTimeout(timer);
            timer = setTimeout(check, WAIT_MS);
        };

        textarea.addEventListener('input', later);

        if (allowChanges) allowChanges.addEventListener('change', check);

        // SQL already in the box (shown again after a submit) is checked at once
        check();
    });
});
