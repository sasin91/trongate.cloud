---
title: Changelog, October 26
kind: changelog
summary: Every app is now backed up every night, encrypted and kept for 30 days, and trongate.cloud has a documentation portal with sample modules to download.
---
Two things today: your apps are backed up every night, and there is now a place to read how trongate.cloud works.

## Nightly backups

Until now, a running app's data had no copy outside the server it runs on. Now every deployed app gets one backup a night, from 02:00 UTC. There is nothing to set up.

Each backup holds:

- **Your database**, dumped in one consistent snapshot, so rows written while the backup runs never leave it half-done.
- **Your app's folder** as it runs: your code, its config files and your uploaded files.
- **Whatever your own backup module adds**, if your app has one (below).

Everything goes into one archive, which is encrypted before it leaves your app. Only trongate.cloud's private key can read it, and that key is not kept on our servers. Backups are kept for **30 days**, then deleted for good, so data you delete is gone from the backups within 30 days too.

The panel's own database is backed up the same way every night.

### Your own backup module

To put more into the backup, such as a CSV export, a report or data from an outside service, add `modules/trongate-cloud/backup/runtime.php` to your app. It runs after the default backup, never instead of it:

- with your app's own code, config files and environment variables
- with `BACKUP_DIR` set to an empty folder: everything you write there goes into the backup
- with your database's settings in `DB_HOST`, `DB_PORT`, `DB_NAME` and `DB_PASSWORD`
- for up to 30 minutes

If it fails, the backup still goes ahead with everything else, and the failure is noted with the backup. [The backups page](/docs/backups) has a sample module to download that saves your tables as CSV files.

### Getting a backup back

Restoring from your app's page is on its way. Until then, tell us the app and the day you want back, and we restore it for you.

## Documentation

trongate.cloud now has documentation at [/docs](/docs), linked from the site's header and footer and from the panel. Two pages to start with:

- [Getting started](/docs/getting-started): putting a Trongate app from GitHub online, and what happens on every push after that.
- [Backups](/docs/backups): what a backup holds, how long it is kept, and how to add your own backup module.

Where a page comes with code, it offers it as a **sample module**: a zip you unzip into your app's root folder, with every file already where it belongs. Change it to whatever your app needs.

More pages will follow as we write them. If something you needed wasn't there, tell us and it goes on the list.
