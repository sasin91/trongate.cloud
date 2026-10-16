<?php

require_once APPPATH . 'modules/platform/live/App_database.php';
require_once APPPATH . 'modules/platform/live/Schema_diff.php';
require_once APPPATH . 'modules/platform/live/Setup_sql_plan.php';
require_once APPPATH . 'modules/platform/tasks/lib/Platform_task.php';
require_once APPPATH . 'modules/services/clients/Github_app.php';
require_once APPPATH . 'modules/services/clients/Github.php';

/**
 * The Database tab's Schema view (?view=schema): the schema.sql in the app's
 * repository next to the database as it is now, one difference per pair of
 * rows (views/_schema.php). schema.sql is read at the commit the app runs
 * (its current deployment), or the branch head before the first deploy,
 * with the GitHub App's installation token, so any project member sees it.
 * Read-only: nothing here writes to the database.
 */
trait App_database_schema {

    /** Where schema.sql is looked for, in this order; the new-app wizard ticks these too */
    const SCHEMA_PATHS = Setup_sql_plan::SCHEMA_PATHS;

    /**
     * Values for views/_schema.php
     *
     * @return array{schema_message: string|null, schema_source: string|null, schema_source_href: string|null, schema_differences: list<array<string, mixed>>, schema_summary: string, schema_skipped: string|null}
     * @throws PDOException
     */
    function _schema_data(object $app, App_database $database): array {
        $data = [
            'schema_message' => null,
            'schema_source' => null,
            'schema_source_href' => null,
            'schema_differences' => [],
            'schema_summary' => '',
            'schema_skipped' => null,
        ];

        $source = $this->_schema_source($app);

        if (is_string($source)) {
            $data['schema_message'] = $source;

            return $data;
        }

        $data['schema_source'] = $source['path'] . ' at ' . $source['ref_label'];
        $data['schema_source_href'] = 'https://github.com/' . $source['repo'] . '/blob/' . rawurlencode($source['ref']) . '/' . $source['path'];

        $live = $database->schema_definition();
        $parsed = Schema_diff::parse($source['sql'], $live['explicit_timestamps']);

        if ($parsed['tables'] === []) {
            $data['schema_message'] = $source['path'] . ' has no CREATE TABLE statements to compare.';

            return $data;
        }

        $current = Schema_diff::from_database($live['columns'], $live['keys'], $live['foreign_keys']);
        $differences = Schema_diff::differences($parsed['tables'], $current);

        $data['schema_differences'] = $this->_schema_rows($app, $differences, $current);
        $data['schema_summary'] = $this->_schema_summary(count($differences), count($parsed['tables']));

        if ($parsed['skipped'] > 0) {
            $data['schema_skipped'] = $parsed['skipped'] . ' ALTER TABLE ' . ($parsed['skipped'] === 1 ? 'change' : 'changes') . ' in ' . $source['path'] . ' could not be read, so they are not compared.';
        }

        return $data;
    }

    /**
     * schema.sql from the app's repository: repo, ref, path and its SQL, or
     * why there is none to show
     *
     * @return array{repo: string, ref: string, ref_label: string, path: string, sql: string}|string
     */
    function _schema_source(object $app): array|string {
        try {
            $repo = Platform_task::github_repo((array) $app);
        } catch (RuntimeException $e) {
            return 'This app has no GitHub repository, so there is no schema.sql to compare.';
        }

        $branch = (string) ($app->source_branch ?: 'main');
        $commit = $this->_running_commit($app);
        $ref = $commit === '' ? $branch : $commit;
        $ref_label = $commit === '' ? $branch : $branch . ' (' . substr($commit, 0, 7) . ', running now)';

        try {
            $github = Platform_task::source_client($repo);
            $found = $this->_find_schema_file($github, $repo, $ref);
        } catch (Client_Error | RuntimeException $e) {
            return 'Could not read ' . $repo . ' on GitHub. Check that the trongate.cloud GitHub App is installed on it, or that it is public.';
        }

        if ($found === null) {
            return 'No schema.sql in ' . $repo . ' at ' . $ref_label . '. Looked for ' . $this->_schema_paths_text() . '.';
        }

        return ['repo' => $repo, 'ref' => $ref, 'ref_label' => $ref_label, 'path' => $found['path'], 'sql' => $found['sql']];
    }

    /**
     * The commit of the app's current deployment, or '' before its first one
     */
    function _running_commit(object $app): string {
        if (empty($app->current_deployment_id)) {
            return '';
        }

        $commit = $this->model->deployment_commit((int) $app->current_deployment_id);

        if (!preg_match('/^[0-9a-f]{40}$/', $commit)) {
            return '';
        }

        return $commit;
    }

    /**
     * The first of SCHEMA_PATHS the repository has at $ref
     *
     * @return array{path: string, sql: string}|null
     * @throws Client_Error
     */
    function _find_schema_file(Github $github, string $repo, string $ref): ?array {
        foreach (self::SCHEMA_PATHS as $path) {
            $sql = $github->file_at($repo, $ref, $path);

            if ($sql !== null) {
                return ['path' => $path, 'sql' => $sql];
            }
        }

        return null;
    }

    /**
     * "schema.sql, database/schema.sql, db/schema.sql or sql/schema.sql"
     */
    function _schema_paths_text(): string {
        $paths = self::SCHEMA_PATHS;
        $last = array_pop($paths);

        return implode(', ', $paths) . ' or ' . $last;
    }

    /**
     * The grid's values: per difference its table (linked when the database
     * has it), what it is, and the schema.sql and database rows
     *
     * @param list<array{table: string, kind: string, name: string, schema: string|null, database: string|null}> $differences
     * @param array<string, mixed> $current Schema_diff::from_database()
     * @return list<array{table: string, table_href: string|null, kind: string, name: string, change: string, change_class: string, schema: string, schema_missing: bool, database: string, database_missing: bool}>
     */
    function _schema_rows(object $app, array $differences, array $current): array {
        $rows = [];

        foreach ($differences as $difference) {
            $table_href = null;

            if (isset($current[$difference['table']])) {
                $table_href = $this->_database_url($app, ['table' => $difference['table'], 'view' => 'structure']);
            }

            $change = $this->_schema_change($difference);

            $rows[] = [
                'table' => $difference['table'],
                'table_href' => $table_href,
                'kind' => $difference['kind'],
                'name' => $difference['kind'] === 'Table' ? '' : $difference['name'],
                'change' => $change,
                'change_class' => 'db-diff-' . strtolower($change),
                'schema' => $difference['schema'] ?? 'Not in schema.sql',
                'schema_missing' => $difference['schema'] === null,
                'database' => $difference['database'] ?? 'Not in the database',
                'database_missing' => $difference['database'] === null,
            ];
        }

        return $rows;
    }

    /**
     * Missing, Extra or Different, seen from schema.sql
     *
     * @param array{schema: string|null, database: string|null} $difference
     */
    function _schema_change(array $difference): string {
        if ($difference['database'] === null) {
            return 'Missing';
        }

        if ($difference['schema'] === null) {
            return 'Extra';
        }

        return 'Different';
    }

    function _schema_summary(int $differences, int $tables): string {
        $compared = $tables . ($tables === 1 ? ' table' : ' tables') . ' in schema.sql';

        if ($differences === 0) {
            return 'The database matches schema.sql (' . $compared . ')';
        }

        return $differences . ($differences === 1 ? ' difference' : ' differences') . ' · ' . $compared;
    }
}
