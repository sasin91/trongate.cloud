Sample modules offered for download on docs pages (a page names one with `sample: folder-name`).

Each folder holds its files exactly where they go in a customer's app, so
`backup/modules/trongate-cloud/backup/runtime.php` unzips into the app's root as
`modules/trongate-cloud/backup/runtime.php`. They are served as `/docs/samples/{folder}.zip`.
Hidden files are left out of the zip. These files are customer code, so PHPStan and
php-cs-fixer skip them.
