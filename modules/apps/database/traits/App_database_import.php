<?php

require_once APPPATH . 'modules/platform/live/Sql_import.php';

/**
 * The Database tab's Import view (?view=import, views/_import.php): upload a
 * .sql file of any size in parts (js/import.js), then the worker checks it,
 * the person runs it, and the worker runs it statement by statement
 * (Sql_import, tasks/import_sql.php). The view polls import/status while
 * the worker is busy.
 *
 * The using controller is Database; its model uses App_sql_imports.
 */
trait App_database_import {

    /**
     * POST apps/{id}/database/import/start (file_name, bytes): start an
     * upload. Answers JSON {import} with the import's id, or 422 JSON with
     * why the file cannot be imported.
     */
    function import_start(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $this->validation->set_rules('file_name', 'file', 'required|max_length[255]|callback__valid_import_file');
        $this->validation->set_rules('bytes', 'file size', 'required|integer');

        if ($this->validation->run() !== true) {
            echo validation_errors(422);
            return;
        }

        $busy = $this->_busy_import($app);

        if ($busy !== null) {
            $this->_import_refused($busy);
            return;
        }

        $import_id = $this->model->create_import((int) $app->id, $this->_database_customer_id(), basename((string) post('file_name')), (int) post('bytes'));

        header('Content-Type: application/json');
        echo json_encode(['import' => $import_id]);
    }

    /**
     * Validation callback: a .sql or .sql.gz file of a size that may be imported
     */
    function _valid_import_file(string $file_name): bool|string {
        return Sql_import::file_refusal($file_name, (int) post('bytes')) ?? true;
    }

    /**
     * POST apps/{id}/database/import/chunk (import, seq, chunk): store one part
     * of the file, Sql_import::CHUNK_BYTES of it (the last one less). A part
     * sent again replaces the first.
     */
    function import_chunk(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $import = $this->model->sql_import((int) $app->id, (int) post('import'));
        $seq = (int) post('seq');
        $bytes = $this->_uploaded_chunk();

        if ($import === false || $import->status !== 'uploading') {
            $this->_import_refused('This upload was stopped or replaced by another one. Start it again.');
            return;
        }

        $refusal = $this->_chunk_refusal($import, $seq, $bytes);

        if ($refusal !== null) {
            error_log("SQL import #{$import->id} of app #{$app->id}: part {$seq} refused: {$refusal}");
            $this->_import_refused($refusal);
            return;
        }

        $this->model->save_chunk((int) $import->id, $seq, (string) $bytes);
        http_response_code(204);
    }

    /**
     * The part in $_FILES['chunk'], or null when none arrived whole
     */
    function _uploaded_chunk(): ?string {
        $file = $_FILES['chunk'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return null;
        }

        $bytes = file_get_contents((string) $file['tmp_name']);

        return $bytes === false ? null : $bytes;
    }

    /**
     * Why part $seq cannot be stored, or null: parts come in order, each
     * CHUNK_BYTES but the last, which holds the rest of the file
     */
    function _chunk_refusal(object $import, int $seq, ?string $bytes): ?string {
        $chunks = Sql_import::chunk_count((int) $import->bytes);
        $received = $this->model->received((int) $import->id);

        if ($bytes === null) {
            return 'Part ' . ($seq + 1) . ' of the file did not arrive whole. Try again.';
        }

        if ($seq < 0 || $seq >= $chunks || $seq > $received['chunks']) {
            return 'Part ' . ($seq + 1) . ' came out of order. Start the upload again.';
        }

        $expected = $seq < $chunks - 1 ? Sql_import::CHUNK_BYTES : (int) $import->bytes - Sql_import::CHUNK_BYTES * ($chunks - 1);

        if (strlen($bytes) !== $expected) {
            return 'Part ' . ($seq + 1) . ' is ' . strlen($bytes) . ' bytes, not ' . $expected . '. Start the upload again.';
        }

        return null;
    }

    /**
     * POST apps/{id}/database/import/finish (import): every part arrived, so
     * queue the check. Answers the Import view's URL to go to.
     */
    function import_finish(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $import = $this->model->sql_import((int) $app->id, (int) post('import'));

        if ($import === false || $import->status !== 'uploading') {
            $this->_import_refused('This upload was stopped or replaced by another one. Start it again.');
            return;
        }

        $received = $this->model->received((int) $import->id);

        if ($received['bytes'] !== (int) $import->bytes || $received['chunks'] !== Sql_import::chunk_count((int) $import->bytes)) {
            error_log("SQL import #{$import->id} of app #{$app->id}: finished with {$received['bytes']} of {$import->bytes} bytes");
            $this->_import_refused('Only ' . $this->_size_label($received['bytes']) . ' of ' . $this->_size_label((int) $import->bytes) . ' arrived. Start the upload again.');
            return;
        }

        $log_id = $this->model->log_action([
            'app_id' => (int) $app->id,
            'customer_id' => $this->_database_customer_id(),
            'action' => 'import',
            'statement' => 'Import ' . $import->file_name . ' (' . $this->_size_label((int) $import->bytes) . ')',
            'allow_changes' => true,
            'affected' => null,
            'error' => null,
        ]);
        $this->model->queue_import($app, (int) $import->id, ['status' => 'checking', 'log_id' => $log_id]);

        echo BASE_URL . $this->_database_url($app, ['view' => 'import']);
    }

    /**
     * GET apps/{id}/database/import/status: the import's panel on its own,
     * for the Import view's poller (MX)
     */
    function import_status(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $this->view('_import_status', $this->_import_data($app, $this->_table_names($app)));
    }

    /**
     * POST apps/{id}/database/import/run (import): run a checked file
     */
    function import_run(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $import = $this->model->sql_import((int) $app->id, (int) post('import'));

        if ($import === false || $import->status !== 'checked' || $import->file_deleted) {
            $this->_import_redirect($app, 'That file is not ready to run. Import it again.');
        }

        $this->model->queue_import($app, (int) $import->id, ['status' => 'running', 'error' => null]);
        $this->_import_redirect($app, null);
    }

    /**
     * POST apps/{id}/database/import/stop (import): stop checking or running
     * after the current statement
     */
    function import_stop(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $import = $this->model->sql_import((int) $app->id, (int) post('import'));
        $stopped = $import !== false && $this->model->stop_import((int) $import->id);

        $this->_import_redirect($app, $stopped ? 'Stopping after the statement that is running now.' : 'That import is not running.');
    }

    /**
     * POST apps/{id}/database/import/continue (import, skip): carry on with a
     * failed or stopped import, from the statement it stopped at, or with
     * skip=1 after it
     */
    function import_continue(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $import = $this->model->sql_import((int) $app->id, (int) post('import'));

        if ($import === false || !$this->_can_continue($import)) {
            $this->_import_redirect($app, 'That import cannot carry on. Import the file again.');
        }

        $phase = $import->total_statements === null ? 'checking' : 'running';
        $skip = $phase === 'running' && $import->status === 'failed' && post('skip') === '1';

        $this->model->queue_import($app, (int) $import->id, ['status' => $phase, 'error' => null, 'can_continue' => 0, 'skip_first' => $skip ? 1 : 0, 'completed_at' => null]);
        $this->_import_redirect($app, null);
    }

    /**
     * POST apps/{id}/database/import/discard (import): delete an import that
     * is not checking or running, and its stored file
     */
    function import_discard(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $import = $this->model->sql_import((int) $app->id, (int) post('import'));

        if ($import !== false) {
            $this->model->discard_import((int) $import->id);
        }

        $this->_import_redirect($app, null);
    }

    /**
     * Why another import cannot start now, or null when it can
     */
    function _busy_import(object $app): ?string {
        $latest = $this->model->latest_import((int) $app->id);

        if ($latest === false || !in_array($latest->status, ['checking', 'running'], true)) {
            return null;
        }

        return $latest->file_name . ' is still being ' . ($latest->status === 'checking' ? 'checked' : 'imported') . '. Stop it first, or wait for it to finish.';
    }

    function _can_continue(object $import): bool {
        if ($import->file_deleted) {
            return false;
        }

        if ($import->status === 'stopped') {
            return true;
        }

        return $import->status === 'failed' && $import->can_continue;
    }

    /**
     * 422 JSON like validation_errors(422), which js/import.js shows above the form
     */
    function _import_refused(string $message): void {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['file' => [$message]], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    function _import_redirect(object $app, ?string $message): never {
        if ($message !== null) {
            set_flashdata(htmlspecialchars($message));
        }

        redirect($this->_database_url($app, ['view' => 'import']));
    }

    /**
     * The app's tables now, to name the ones an import drops or empties; null when the database cannot be reached
     *
     * @return list<string>|null
     */
    function _table_names(object $app): ?array {
        $database = $this->_open_app_database($app);

        if (is_string($database)) {
            return null;
        }

        try {
            return array_keys($database->tables());
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * Everything views/_import.php and views/_import_status.php show, as plain values
     *
     * @param list<string>|null $table_names the app's tables now (null when unknown)
     * @return array<string, mixed>
     */
    function _import_data(object $app, ?array $table_names): array {
        $base = 'apps/' . $app->id . '/database/import';
        $import = $this->model->latest_import((int) $app->id);
        $busy = $import !== false && in_array($import->status, ['checking', 'running'], true);

        return [
            'import_base' => $base,
            'import_status_url' => $base . '/status',
            'import_start_url' => $base . '/start',
            'import_chunk_url' => BASE_URL . $base . '/chunk',
            'import_finish_url' => BASE_URL . $base . '/finish',
            'import_chunk_bytes' => Sql_import::CHUNK_BYTES,
            'import_max_label' => intdiv(Sql_import::MAX_BYTES, 1024 * 1024 * 1024) . ' GB',
            'import_can_upload' => !$busy,
            'import_replaces' => $import !== false && !$busy && $import->status !== 'done' ? (string) $import->file_name : null,
            'import_polling' => $busy,
            'import' => $import === false ? null : $this->_import_panel($import, $table_names),
        ];
    }

    /**
     * One import as the view shows it: a heading, lines under it, a progress
     * bar (percent, or null without one) and the buttons it offers
     *
     * @param list<string>|null $table_names
     * @return array{id: int, heading: string, lines: list<string>, warning: string|null, error: string|null, percent: int|null, failed: bool, actions: list<array{label: string, path: string, skip: bool, primary: bool}>}
     */
    function _import_panel(object $import, ?array $table_names): array {
        $panel = [
            'id' => (int) $import->id,
            'heading' => '',
            'lines' => [],
            'warning' => null,
            'error' => null,
            'percent' => null,
            'failed' => false,
            'actions' => [],
        ];
        $file = $import->file_name . ' (' . $this->_size_label((int) $import->bytes) . ')';
        $run = (int) $import->statements_run;
        $total = (int) $import->total_statements;

        if ($import->status === 'uploading') {
            $panel['heading'] = 'The upload of ' . $file . ' did not finish';
            $panel['lines'][] = 'Nothing ran. Import the file again.';
            $panel['actions'][] = $this->_import_action('Discard', 'discard');
        } elseif ($import->status === 'checking') {
            $panel['heading'] = 'Checking ' . $file;
            $panel['lines'][] = 'Statement ' . number_format((int) $import->statement_number) . ', line ' . number_format((int) $import->line) . '. Nothing runs until you say so.';
            $panel['percent'] = $import->compressed ? null : $this->_percent((int) $import->position, (int) $import->bytes);
            $panel['actions'][] = $this->_import_action('Stop', 'stop');
        } elseif ($import->status === 'checked') {
            $panel = $this->_checked_panel($import, $file, $panel, $table_names);
        } elseif ($import->status === 'running') {
            $panel['heading'] = 'Importing ' . $file;
            $panel['lines'][] = number_format($run) . ' of ' . number_format($total) . ' statements ran, now at line ' . number_format((int) $import->line) . '.';
            $panel['percent'] = $this->_percent($run, $total);
            $panel['actions'][] = $this->_import_action('Stop', 'stop');
        } elseif ($import->status === 'done') {
            $panel['heading'] = 'Imported ' . $file;
            $panel['lines'][] = $this->_import_counts($run, (int) $import->statements_skipped) . ' Finished ' . substr((string) $import->completed_at, 0, 16) . ' UTC.';
            $panel['percent'] = 100;
        } else {
            $panel = $this->_stopped_panel($import, $file, $panel);
        }

        return $panel;
    }

    /**
     * @param array<string, mixed> $panel
     * @param list<string>|null $table_names
     * @return array<string, mixed>
     */
    function _checked_panel(object $import, string $file, array $panel, ?array $table_names): array {
        $summary = json_decode((string) $import->summary, true);
        $keywords = is_array($summary['keywords'] ?? null) ? $summary['keywords'] : [];
        $emptied = $this->_emptied_tables(is_array($summary['emptied'] ?? null) ? $summary['emptied'] : [], $table_names);
        $total = (int) $import->total_statements;
        $counts = [];

        arsort($keywords);

        foreach ($keywords as $keyword => $count) {
            $counts[] = number_format((int) $count) . ' ' . $keyword;
        }

        $panel['heading'] = $file . ' is ready to run';
        $panel['lines'][] = number_format($total) . ($total === 1 ? ' statement' : ' statements') . ($counts === [] ? '' : ': ' . implode(', ', $counts)) . '.';

        $skipped = (int) ($summary['skipped'] ?? 0);

        if ($skipped > 0) {
            $panel['lines'][] = number_format($skipped) . ' more are skipped: transactions, locks, client settings, views in /*! */ comments, USE and CREATE DATABASE.';
        }

        $panel['lines'][] = 'They run one by one into ' . Manifests::database_name((int) $import->app_id) . ' as your app\'s own user. Nothing is backed up first.';

        $panel['warning'] = $this->_emptied_warning($emptied);

        if ($import->file_deleted) {
            $panel['lines'][] = 'The file was deleted after ' . Sql_import::KEEP_HOURS . ' hours. Import it again to run it.';
            $panel['actions'][] = $this->_import_action('Discard', 'discard');

            return $panel;
        }

        $panel['actions'][] = $this->_import_action('Run ' . number_format($total) . ($total === 1 ? ' statement' : ' statements'), 'run', false, true);
        $panel['actions'][] = $this->_import_action('Discard', 'discard');

        return $panel;
    }

    /**
     * The warning above Run when the file drops or empties tables the app has, or null
     *
     * @param list<string> $emptied
     */
    function _emptied_warning(array $emptied): ?string {
        if ($emptied === []) {
            return null;
        }

        $shown = array_slice($emptied, 0, 20);
        $names = implode(', ', $shown);

        if (count($emptied) > count($shown)) {
            $names .= ' and ' . (count($emptied) - count($shown)) . ' more';
        }

        if (count($emptied) === 1) {
            return 'It drops or empties the table ' . $names . ': the rows it holds now are gone for good.';
        }

        return 'It drops or empties the tables ' . $names . ': the rows they hold now are gone for good.';
    }

    /**
     * The tables a file drops or empties that hold rows now: those of them
     * the app has (all of them when its tables are unknown)
     *
     * @param list<string> $emptied
     * @param list<string>|null $table_names
     * @return list<string>
     */
    function _emptied_tables(array $emptied, ?array $table_names): array {
        if ($table_names === null) {
            return $emptied;
        }

        $existing = array_map('strtolower', $table_names);
        $tables = [];

        foreach ($emptied as $table) {
            if (in_array(strtolower($table), $existing, true)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * A failed or stopped import
     *
     * @param array<string, mixed> $panel
     * @return array<string, mixed>
     */
    function _stopped_panel(object $import, string $file, array $panel): array {
        $checking = $import->total_statements === null;
        $run = (int) $import->statements_run;

        $panel['failed'] = $import->status === 'failed';
        $panel['heading'] = $panel['failed'] ? 'The import of ' . $file . ' stopped' : 'You stopped ' . $file;
        $panel['error'] = $import->error === null ? null : (string) $import->error;
        $panel['lines'][] = $checking
            ? 'Nothing ran: this was while checking it.'
            : $this->_import_counts($run, (int) $import->statements_skipped) . ($run > 0 ? ' What they changed stays in your database.' : '');

        if ($this->_can_continue($import)) {
            $panel['actions'][] = $this->_import_action($panel['failed'] ? 'Try it again' : 'Carry on', 'continue', false, true);

            if ($panel['failed'] && !$checking) {
                $panel['actions'][] = $this->_import_action('Skip it and carry on', 'continue', true);
            }
        } elseif ($panel['failed'] && $checking) {
            $panel['lines'][] = 'Fix the statement in the file, then import it again.';
        }

        $panel['actions'][] = $this->_import_action('Discard', 'discard');

        return $panel;
    }

    /**
     * @return array{label: string, path: string, skip: bool, primary: bool}
     */
    function _import_action(string $label, string $path, bool $skip = false, bool $primary = false): array {
        return ['label' => $label, 'path' => $path, 'skip' => $skip, 'primary' => $primary];
    }

    function _import_counts(int $run, int $skipped): string {
        $text = number_format($run) . ($run === 1 ? ' statement ran' : ' statements ran');

        if ($skipped > 0) {
            $text .= ', ' . number_format($skipped) . ' skipped';
        }

        return $text . '.';
    }

    function _percent(int $done, int $total): int {
        if ($total <= 0) {
            return 0;
        }

        return min(100, intdiv($done * 100, $total));
    }
}
