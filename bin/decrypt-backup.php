<?php

/**
 * Reads a nightly backup (Backup_file) downloaded from the backups bucket:
 *
 *   APP_BACKUPS_PRIVATE_KEY=... php bin/decrypt-backup.php database.sql.gz.enc database.sql.gz
 *
 * The result is the plain database.sql.gz (import it with
 * `gunzip -c database.sql.gz | mariadb {database}`), files.tar.gz (unpack it
 * with `tar -xzf files.tar.gz -C {folder}`) or platform.sql.gz. It refuses a
 * backup that was changed, cut off or encrypted for another key.
 */

require __DIR__ . '/../modules/platform/live/Backup_file.php';

[, $in, $out] = $argv + [null, null, null];
$private_key = getenv('APP_BACKUPS_PRIVATE_KEY');

if ($in === null || $out === null || $private_key === false || $private_key === '') {
    fwrite(STDERR, "Usage: APP_BACKUPS_PRIVATE_KEY=... php bin/decrypt-backup.php {backup.enc} {output}\n");
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
