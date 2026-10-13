<?php

/**
 * Import SQL Task
 *
 * Checks or runs part of a .sql file imported from the Database tab
 * (Sql_import, Sql_import_run): up to Sql_import::SLICE_SECONDS of it, then
 * queues itself again, so other tasks run in between.
 *
 * Payload: {import_id, app_id, project_id}
 *
 * Queued by the Database tab (App_database_import) once the upload is
 * complete (checking), when the person runs it or carries on after a
 * failure (running), and by check_sql_imports when a task died on the way.
 * An import stopped meanwhile is left as it is.
 */

require_once __DIR__ . '/lib/Sql_import_run.php';

return function (Task_log $log) use ($payload, $runtime, $task): int {
    $db = $runtime->db();
    $project_id = (int) $payload['project_id'];
    $app_id = (int) $payload['app_id'];
    $import_id = (int) $payload['import_id'];

    $import = Platform_task::row($db, 'SELECT * FROM app_sql_import WHERE id = ? AND app_id = ?', [$import_id, $app_id]);

    if ($import === null || !in_array($import['status'], ['checking', 'running'], true)) {
        $log->info('Nothing to do: the import was stopped, discarded or has ended');

        return 0;
    }

    Platform_task::execute($db, 'UPDATE app_sql_import SET task_id = ? WHERE id = ?', [(int) $task['id'], $import_id]);

    $app = Platform_task::app($db, $project_id, $app_id);
    $server = empty($app['database_server_id'])
        ? null
        : Platform_task::row($db, 'SELECT * FROM database_server WHERE id = ?', [(int) $app['database_server_id']]);

    $log->info(($import['status'] === 'checking' ? 'Checking ' : 'Running ') . $import['file_name'] . " from line {$import['line']}");

    try {
        $outcome = (new Sql_import_run($db, $log, $import))->share($server);
    } catch (RuntimeException $e) {
        Platform_task::execute(
            $db,
            "UPDATE app_sql_import SET status = 'failed', error = ?, can_continue = 0, completed_at = NOW() WHERE id = ? AND status IN ('checking', 'running')",
            [$e->getMessage(), $import_id],
        );
        $log->error('The import stopped: ' . $e->getMessage());

        return 1;
    }

    if ($outcome === Sql_import_run::MORE) {
        Runtime::run($db, 'INSERT INTO tasks (project_id, app_id, script, payload) VALUES (?, ?, ?, ?)', [
            $project_id,
            $app_id,
            'import_sql',
            json_encode(['import_id' => $import_id, 'app_id' => $app_id, 'project_id' => $project_id]),
        ]);
    }

    return 0;
};
