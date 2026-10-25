<?php

/**
 * Makes the key pair the nightly backups are encrypted to (Backup_file):
 *
 *   php bin/backup-keys.php
 *
 * Put APP_BACKUPS_PUBLIC_KEY in the trongate-cloud-backups Secret. Keep
 * APP_BACKUPS_PRIVATE_KEY offline (a password manager, and a second copy
 * elsewhere): it is the only way to read a backup, and it does not go on
 * the cluster.
 */

require __DIR__ . '/../modules/platform/live/Backup_file.php';

$keys = Backup_file::new_keys();

echo 'APP_BACKUPS_PUBLIC_KEY=' . $keys['public'] . "\n";
echo 'APP_BACKUPS_PRIVATE_KEY=' . $keys['private'] . "\n";
