<?php

/**
 * The nightly backups, one row each in daily_backup: every running app, and
 * the platform's own database.
 *
 * Every night from HOUR (UTC) the periodic task back_up_daily starts a Job
 * per app (Manifests::daily_backup_job), a few at a time. The Job dumps the
 * app's database, packs /app as the app sees it (code, config, persistent
 * files), runs the app's own backup module if it has one (docs/backups.md),
 * and uploads all of it as one tar.gz, encrypted to APP_BACKUPS_PUBLIC_KEY
 * (Backup_file), through a signed link into the backups bucket
 * (Object_storage::for_app_backups). The worker dumps the platform database
 * itself the same way. Each backup is kept KEEP_DAYS, then deleted.
 *
 * Framework-free: the worker and the panel both use it.
 */
final class Daily_backup {

    /** How long a backup is kept */
    const KEEP_DAYS = 30;

    /** Backups start from this hour of the night, UTC */
    const HOUR = 2;

    /** App backup Jobs running at once, so the shared database server is not swamped */
    const RUNNING_AT_ONCE = 2;

    /** The Job gets this long before Kubernetes stops it */
    const JOB_SECONDS = 3600;

    /** Upload links outlive the Job, so an upload that started in time can finish */
    const UPLOAD_LINK_SECONDS = self::JOB_SECONDS + 900;

    /** The one object of a backup, by source */
    const OBJECTS = [
        'app' => 'backup.tar.gz.enc',
        'platform' => 'platform.sql.gz.enc',
    ];

    const STATUSES = ['backing_up', 'ready', 'failed', 'expired'];

    /** The Job's name, inside the app's p{id}- prefix like its other objects */
    static function job_name(int $app_id): string {
        return "p{$app_id}-daily-backup";
    }

    /**
     * Where an app's new backup goes: its day, then a random part so a link
     * to one backup never fits another
     */
    static function new_app_prefix(int $app_id, int $now): string {
        return "daily/app-{$app_id}/" . gmdate('Y-m-d', $now) . '-' . bin2hex(random_bytes(8)) . '/';
    }

    static function new_platform_prefix(int $now): string {
        return 'daily/platform/' . gmdate('Y-m-d', $now) . '-' . bin2hex(random_bytes(8)) . '/';
    }

    /**
     * The object key of a backup
     *
     * @param 'app'|'platform' $source
     */
    static function key(string $prefix, string $source): string {
        if (!isset(self::OBJECTS[$source])) {
            throw new InvalidArgumentException("Unknown backup source: {$source}");
        }

        return $prefix . self::OBJECTS[$source];
    }

    /**
     * Whether tonight's backups may start: from HOUR until midnight UTC, so a
     * worker that was down at HOUR still takes them later that night
     */
    static function due(int $now): bool {
        return (int) gmdate('G', $now) >= self::HOUR;
    }
}
