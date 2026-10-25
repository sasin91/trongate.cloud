---
title: Backups
summary: Every app is backed up every night, kept for 30 days and encrypted. Add your own backup module to put more in it.
order: 20
sample: backup
---
Trongate.cloud backs up every app every night. There is nothing to set up.

## What a backup holds

Each night, from 02:00 UTC, every app that has been deployed gets one backup:

- **Your database**, dumped in one consistent snapshot, so rows written while the backup runs never leave it half-done.
- **Your app's folder** as it runs: your code, its config files and your uploaded files (the persistent paths in your app's settings).
- **Whatever your own backup module adds**, if your app has one (below).

All of it goes into one archive, which is encrypted before it leaves your app's namespace. Only Trongate.cloud's private key can read it, and that key is not kept on the servers.

Backups are kept for **30 days**, then deleted for good. That includes data someone asked you to delete: it is gone from the backups within 30 days.

## Your own backup module

Add `modules/trongate-cloud/backup/runtime.php` to your app to put more into the backup: a CSV export, data from an outside service, a report. It runs after the default backup, never instead of it.

It runs:

- with your app's own image, config files and environment variables, from your app's folder, so `require 'config/database.php';` works as it does in your app
- with `BACKUP_DIR` set to an empty folder: everything you write there is added to the backup
- for up to 30 minutes

When it fails (a non-zero exit, an error, or the 30 minutes run out), the backup still goes ahead with everything else, and the failure is noted with the backup. Its output is kept in the backup as `custom.log`.

Download the sample below and unzip it into your app's root folder. It saves your tables as CSV files, which open straight in a spreadsheet. Change it to whatever your app needs.

```php
<?php

$backup_dir = getenv('BACKUP_DIR');

// Your app's own config: the same database your app uses
require 'config/database.php';

file_put_contents("{$backup_dir}/report.txt", 'Anything written here is in the backup');
```

## Limits

- The whole backup must stay under 5 GB.
- A backup gets an hour in all, your module's 30 minutes included.

## Getting a backup back

Restoring from your app's page is on its way. Until then, ask us with the app and the day you want back, and we restore it for you.

A backup holds:

- `database/database.sql.gz`: your database. Import it with `gunzip -c database.sql.gz | mariadb your_database`.
- `app/app.tar`: your app's folder, with its code, config and uploaded files.
- `app/custom/`: what your backup module wrote.
- `app/custom.log`: your backup module's output.
