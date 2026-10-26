<?php

/**
 * A sample backup module for Trongate.cloud (see /docs/backups).
 *
 * Trongate.cloud already backs up your database, your code and your uploaded
 * files every night. When this file exists, it runs right after that, and
 * whatever it writes into BACKUP_DIR is added to the same backup.
 *
 * This sample saves the tables listed in TABLES as CSV files, so they open
 * straight in a spreadsheet. Change it to whatever your app needs: data from
 * an outside service, a report, a second database.
 *
 * It runs with your app's own image, config files and environment variables,
 * from your app's folder, for up to 30 minutes. Exit with a non-zero code to
 * report a failure; the rest of the backup is kept either way.
 */

// The tables to export; leave empty to export every table
const TABLES = [];

$backup_dir = getenv('BACKUP_DIR');

if ($backup_dir === false || $backup_dir === '') {
    fwrite(STDERR, "BACKUP_DIR is not set: this script is run by Trongate.cloud's nightly backup\n");
    exit(1);
}

// Trongate.cloud sets your app's database settings for this script
$pdo = new PDO(
    'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
    getenv('DB_NAME'),
    getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
);

$tables = TABLES ?: $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    $out = fopen("{$backup_dir}/{$table}.csv", 'w');
    $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`', PDO::FETCH_ASSOC);
    $count = 0;

    foreach ($rows as $row) {
        if ($count === 0) {
            fputcsv($out, array_keys($row));
        }

        fputcsv($out, $row);
        $count++;
    }

    $rows->closeCursor();
    fclose($out);
    echo "Exported {$table} ({$count} rows)\n";
}
