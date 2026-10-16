// "Comment out" under a live SQL check (views/_result.php): puts -- before the
// lines of the statements it names ("6-6,12-15") in the SQL box the result
// describes (aria-describedby), then fires change so Trongate MX checks it again.
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-sql-comment-lines]');
    if (!button) return;

    const result = button.closest('.sql-check');
    const textarea = result ? document.querySelector('textarea[aria-describedby="' + result.id + '"]') : null;
    if (!textarea) return;

    const lines = textarea.value.split('\n');

    button.dataset.sqlCommentLines.split(',').forEach(function (range) {
        const ends = range.split('-').map(Number);

        for (let line = ends[0]; line <= ends[1]; line++) {
            if (lines[line - 1] !== undefined && lines[line - 1].trim() !== '') {
                lines[line - 1] = '-- ' + lines[line - 1];
            }
        }
    });

    textarea.value = lines.join('\n');
    textarea.focus();
    textarea.dispatchEvent(new Event('change', { bubbles: true }));
});
