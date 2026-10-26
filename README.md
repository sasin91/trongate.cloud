# log

Version 1.0.1

A log after Monolog, for web requests and command-line scripts, framework-free:

```php
$log = new Log([new Stream_writer('[backup] ')]);      // STDOUT, e.g. a container's log
$log = new Log([new Error_log_writer('[webhooks] ')]); // PHP's error_log()
$log->info('Dumped 12 tables');
$log->warn('...');
$log->error('...');
```

Each message goes to every writer as one entry (a multi-line message stays one entry, like Monolog's records); a blank one is skipped, and a writer that fails never stops the caller. Write a `Log_writer` to send messages elsewhere (the worker's `Task_log_writer` writes them to `task_logs`).

## Wiring

Nothing to register: the folder doesn't need the Trongate engine. Code
requires the one file it uses (a script outside Trongate passes its own path):

```php
require_once APPPATH . 'modules/log/Log.php';
```

`Log.php` requires the writers that ship with it.

## Tests

`tests/*.phpt`, run with php-src's `run-tests.php`:

```sh
php bin/run-tests.php -q modules/log
```
