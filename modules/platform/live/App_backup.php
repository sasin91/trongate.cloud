<?php

/**
 * The backup taken of a deleted app, one row per app in app_backup.
 *
 * Deleting an app (tasks/destroy_app.php) takes it offline, then runs a
 * one-off Job in its namespace (Manifests::backup_job) that dumps its
 * database and packs its persistent files, uploads both to the backups
 * bucket (Object_storage::for_app_backups) through signed links, and then
 * drops the database and empties the files. Once the Job has succeeded,
 * tasks/check_app_backups.php marks the backup ready and queues destroy_app
 * again to remove the rest. The app's project can download both files for
 * KEEP_DAYS; after that they are deleted from the bucket.
 *
 * When the Job fails, nothing is dropped or emptied: the backup is failed,
 * the database and files stay where they were, and running destroy_app
 * again (the admin MCP's retry_app_cleanup) starts a new Job.
 *
 * Framework-free: the worker and the panel both use it.
 */
final class App_backup {

    /** How long the project can download the backup after it is taken */
    const KEEP_DAYS = 7;

    /** The Job gets this long before Kubernetes stops it */
    const JOB_SECONDS = 3600;

    /** Upload links outlive the Job, so an upload that started in time can finish */
    const UPLOAD_LINK_SECONDS = self::JOB_SECONDS + 900;

    /** Download links are made on each click, so they only need to last until the download starts */
    const DOWNLOAD_LINK_SECONDS = 600;

    /** The parts of a backup: what the Job writes, and the file name a download gets */
    const PARTS = [
        'database' => 'database.sql.gz',
        'files' => 'files.tar.gz',
    ];

    const STATUSES = ['backing_up', 'ready', 'failed', 'expired'];

    /** The Job's name, inside the app's p{id}- prefix like its other objects */
    static function job_name(int $app_id): string {
        return "p{$app_id}-backup";
    }

    /**
     * Where a new backup's objects go: a random folder per backup, so a link
     * to one backup never fits another
     */
    static function new_prefix(int $app_id): string {
        return "app-backups/app-{$app_id}/" . bin2hex(random_bytes(16)) . '/';
    }

    /** The object key of one part of the backup */
    static function key(string $prefix, string $part): string {
        if (!isset(self::PARTS[$part])) {
            throw new InvalidArgumentException("Unknown backup part: {$part}");
        }

        return $prefix . self::PARTS[$part];
    }

    /** The name a downloaded part is saved under, such as "my-app-database.sql.gz" */
    static function download_name(string $slug, string $part): string {
        $safe_slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));

        if ($safe_slug === '') {
            $safe_slug = 'app';
        }

        return $safe_slug . '-' . self::PARTS[$part];
    }

    /** response-content-disposition for a download link, so the browser saves the file under its name */
    static function download_query(string $slug, string $part): array {
        $name = self::download_name($slug, $part);

        return ['response-content-disposition' => 'attachment; filename="' . $name . '"'];
    }
}
