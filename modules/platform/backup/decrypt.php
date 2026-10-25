<?php

/**
 * Reads a nightly backup (Backup_file) downloaded from the backups bucket:
 *
 *   APP_BACKUPS_PRIVATE_KEY=... composer backup:decrypt -- backup.tar.gz.enc backup.tar.gz
 *
 * An app's backup.tar.gz holds database/database.sql.gz, app/app.tar (its
 * /app: code, config and persistent files) and app/custom/ (what its own
 * backup module wrote, if any); docs/backups.md says how to restore each.
 * The platform's backup is platform.sql.gz. It refuses a backup that was
 * changed, cut off or encrypted for another key.
 */

require __DIR__ . '/../live/Backup_file.php';

[, $in, $out] = $argv + [null, null, null];
$private_key = getenv('APP_BACKUPS_PRIVATE_KEY');

if ($in === null || $out === null || $private_key === false || $private_key === '') {
    fwrite(STDERR, "Usage: APP_BACKUPS_PRIVATE_KEY=... composer backup:decrypt -- {backup.enc} {output}\n");
    exit(2);
}

if (file_exists($out)) {
    fwrite(STDERR, "{$out} exists already; pick another output\n");
    exit(2);
}

try {
    Backup_file::decrypt($in, $out, $private_key);
} catch (RuntimeException $e) {
    @unlink($out);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Wrote {$out}\n";
