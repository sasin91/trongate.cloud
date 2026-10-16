// Live SQL check, like Laravel Precognition, for any SQL box: while SQL is typed
// or pasted into a textarea with data-sql-check (sql/check), the server runs the
// checks named by data-sql-check-rules (the ones its real submit runs), without
// running the SQL, and its answer (views/_result.php) is shown in the element
// data-sql-check-result names.
// Its "Comment out" button puts -- before the lines of the statements it names.
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
            // Sent as typed, so the lines it names are the box's lines
            const sql = textarea.value;
            const asked = sql + (allowChanges && allowChanges.checked ? '\n1' : '\n0');

            if (asked === checked) return;
            checked = asked;

            if (request) request.abort();

            if (sql.trim() === '') {
                show('');
                return;
            }

            const body = new FormData();
            body.append('sql', sql);
            body.append('rules', textarea.dataset.sqlCheckRules || 'database');

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

        // "6-6,12-15": put -- before each of those lines that has anything on it
        const commentOut = function (ranges) {
            const lines = textarea.value.split('\n');

            ranges.split(',').forEach(function (range) {
                const ends = range.split('-').map(Number);

                for (let line = ends[0]; line <= ends[1]; line++) {
                    if (lines[line - 1] !== undefined && lines[line - 1].trim() !== '') {
                        lines[line - 1] = '-- ' + lines[line - 1];
                    }
                }
            });

            textarea.value = lines.join('\n');
            textarea.focus();
            check();
        };

        result.addEventListener('click', function (event) {
            const button = event.target.closest('[data-sql-comment-lines]');
            if (button) commentOut(button.dataset.sqlCommentLines);
        });

        textarea.addEventListener('input', later);

        if (allowChanges) allowChanges.addEventListener('change', check);

        // SQL already in the box (shown again after a submit) is checked at once
        check();
    });
});
