<?php

require_once __DIR__ . '/App_logs.php';

/**
 * Error and warning rates of customer apps, sampled from their logs.
 *
 * The worker's sample_app_logs task reads each running app's new log lines
 * (App_logs::read, from a cursor per app) about once a minute and adds them
 * up per level in 5-minute windows (app_log_rate). An asleep app has no pods,
 * so it is skipped, never woken; lines a pod writes in its last minute before
 * it goes to sleep may be missed. The app page reads the windows back.
 *
 * Framework-free: the worker records, the web app reads through its model.
 */
class App_log_rates {

    /** Width of one window in app_log_rate.bucket */
    const BUCKET_SECONDS = 300;

    /** Windows are kept this long */
    const RETENTION_DAYS = 7;

    /** Lines read per pod on one sample; more than this in a minute are counted on the next */
    const SAMPLE_TAIL_LINES = 5000;

    /**
     * Counts per window of log lines (App_logs::parse), keyed by the window's
     * start as a UTC "Y-m-d H:i:s".
     *
     * @param list<array{time: string, level: string}> $lines
     * @return array<string, array{total: int, warnings: int, errors: int}>
     */
    static function buckets(array $lines): array {
        $buckets = [];

        foreach ($lines as $line) {
            $seconds = strtotime(substr($line['time'], 0, 19) . 'Z');

            if ($seconds === false) {
                continue;
            }

            $bucket = gmdate('Y-m-d H:i:s', $seconds - $seconds % self::BUCKET_SECONDS);
            $buckets[$bucket] ??= ['total' => 0, 'warnings' => 0, 'errors' => 0];
            $buckets[$bucket]['total']++;

            if ($line['level'] === 'warn') {
                $buckets[$bucket]['warnings']++;
            } elseif ($line['level'] === 'error') {
                $buckets[$bucket]['errors']++;
            }
        }

        ksort($buckets);

        return $buckets;
    }

    /**
     * Read one app's new lines and add them to its windows; returns the counts
     * added. Asleep or not-yet-running apps add nothing and keep their cursor.
     *
     * @param object|array $app an app row with id and its project's namespace
     * @return array{total: int, warnings: int, errors: int}
     */
    static function sample(PDO $db, Kubernetes $kube, object|array $app): array {
        $app = (array) $app;
        $app_id = (int) $app['id'];

        $statement = $db->prepare('SELECT cursor_time FROM app_log_cursor WHERE app_id = ?');
        $statement->execute([$app_id]);
        $cursor = (string) ($statement->fetchColumn() ?: '');

        $read = App_logs::read($kube, Manifests::namespace_name($app), $app_id, $cursor, self::SAMPLE_TAIL_LINES);
        $total = ['total' => 0, 'warnings' => 0, 'errors' => 0];

        if ($read['lines'] === []) {
            return $total;
        }

        $upsert = $db->prepare(
            'INSERT INTO app_log_rate (app_id, bucket, total, warnings, errors) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE total = total + VALUES(total), warnings = warnings + VALUES(warnings), errors = errors + VALUES(errors)'
        );

        $db->beginTransaction();

        try {
            foreach (self::buckets($read['lines']) as $bucket => $counts) {
                $upsert->execute([$app_id, $bucket, $counts['total'], $counts['warnings'], $counts['errors']]);

                foreach ($counts as $key => $count) {
                    $total[$key] += $count;
                }
            }

            $db->prepare(
                'INSERT INTO app_log_cursor (app_id, cursor_time) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE cursor_time = VALUES(cursor_time)'
            )->execute([$app_id, $read['cursor']]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $total;
    }

    /**
     * Delete windows older than RETENTION_DAYS; returns how many
     */
    static function prune(PDO $db): int {
        $statement = $db->prepare('DELETE FROM app_log_rate WHERE bucket < UTC_TIMESTAMP() - INTERVAL ? DAY');
        $statement->execute([self::RETENTION_DAYS]);

        return $statement->rowCount();
    }

    /**
     * Hourly totals for the last $hours hours, oldest first, with every hour
     * present (zero when nothing was logged). $rows are app_log_rate rows of
     * that span; the current hour is the last entry.
     *
     * @param list<array{bucket: string, total: int|string, warnings: int|string, errors: int|string}> $rows
     * @return list<array{hour: string, total: int, warnings: int, errors: int}>
     */
    static function hourly(array $rows, int $now, int $hours = 24): array {
        $current_hour = $now - $now % 3600;
        $series = [];

        for ($i = $hours - 1; $i >= 0; $i--) {
            $hour = gmdate('Y-m-d H:00:00', $current_hour - $i * 3600);
            $series[$hour] = ['hour' => $hour, 'total' => 0, 'warnings' => 0, 'errors' => 0];
        }

        foreach ($rows as $row) {
            $hour = substr((string) $row['bucket'], 0, 13) . ':00:00';

            if (isset($series[$hour])) {
                $series[$hour]['total'] += (int) $row['total'];
                $series[$hour]['warnings'] += (int) $row['warnings'];
                $series[$hour]['errors'] += (int) $row['errors'];
            }
        }

        return array_values($series);
    }
}
