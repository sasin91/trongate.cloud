// Database tab, Import view: uploads the picked .sql file in parts, one
// request each (App_database_import::import_chunk), so a file of any size
// gets past the request size limits, then asks the panel to check it and
// follows its answer to the Import view. A part that fails is sent again a
// few times. The form may be swapped by the view's poller, hence the
// listener on the document.
(function () {
    const ATTEMPTS = 3;

    function showError(form, message) {
        const error = form.querySelector('[data-import-error]');
        error.textContent = message;
        error.hidden = false;
        form.querySelector('[data-import-progress]').hidden = true;
        form.querySelector('[data-import-progress-text]').hidden = true;
    }

    function showProgress(form, sent, total) {
        const bar = form.querySelector('[data-import-progress]');
        const text = form.querySelector('[data-import-progress-text]');
        bar.hidden = false;
        text.hidden = false;
        bar.firstElementChild.style.width = Math.floor(sent * 100 / total) + '%';
        text.textContent = 'Uploading ' + megabytes(sent) + ' of ' + megabytes(total) + '. Keep this page open.';
    }

    function megabytes(bytes) {
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    // The first message of a 422 answer ({"field": ["message"]}), or a general one
    async function refusal(response) {
        try {
            const errors = await response.json();
            const first = Object.values(errors)[0];
            return Array.isArray(first) ? first[0] : String(first);
        } catch (e) {
            return 'The upload failed (' + response.status + '). Try again.';
        }
    }

    function post(url, form, fields) {
        const body = new FormData();
        body.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
        Object.keys(fields).forEach(function (name) {
            body.append(name, fields[name]);
        });

        return fetch(url, {method: 'POST', body: body, headers: {'Accept': 'application/json'}});
    }

    // One part, sent again after a network error or a server error
    async function sendChunk(form, importId, seq, blob) {
        let response = null;

        for (let attempt = 1; attempt <= ATTEMPTS; attempt++) {
            try {
                response = await post(form.dataset.chunkUrl, form, {import: importId, seq: seq, chunk: blob});
            } catch (e) {
                response = null;
            }

            if (response !== null && response.status < 500) {
                return response;
            }
        }

        return response;
    }

    async function upload(form, file) {
        const chunkBytes = parseInt(form.dataset.chunkBytes, 10);
        const started = await post(form.action, form, {file_name: file.name, bytes: file.size});

        if (!started.ok) {
            throw new Error(await refusal(started));
        }

        const importId = (await started.json()).import;

        for (let seq = 0; seq * chunkBytes < file.size; seq++) {
            const blob = file.slice(seq * chunkBytes, (seq + 1) * chunkBytes);
            const sent = await sendChunk(form, importId, seq, blob);

            if (sent === null) {
                throw new Error('The connection dropped. Start the upload again.');
            }

            if (!sent.ok) {
                throw new Error(await refusal(sent));
            }

            showProgress(form, Math.min(file.size, (seq + 1) * chunkBytes), file.size);
        }

        const finished = await post(form.dataset.finishUrl, form, {import: importId});

        if (!finished.ok) {
            throw new Error(await refusal(finished));
        }

        return (await finished.text()).trim();
    }

    function warnBeforeLeaving(event) {
        event.preventDefault();
        event.returnValue = '';
    }

    async function onSubmit(event) {
        const form = event.target.closest('[data-sql-import]');
        if (!form) {
            return;
        }

        event.preventDefault();
        const file = form.querySelector('input[type="file"]').files[0];
        const button = form.querySelector('button[type="submit"]');

        if (!file) {
            return;
        }

        button.disabled = true;
        form.querySelector('[data-import-error]').hidden = true;
        showProgress(form, 0, file.size);
        window.addEventListener('beforeunload', warnBeforeLeaving);

        try {
            const next = await upload(form, file);
            window.removeEventListener('beforeunload', warnBeforeLeaving);
            window.location.href = next;
        } catch (e) {
            window.removeEventListener('beforeunload', warnBeforeLeaving);
            showError(form, e.message);
            button.disabled = false;
        }
    }

    document.addEventListener('submit', onSubmit);
})();
