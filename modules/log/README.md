# log

Version 1.1.0

A log after Monolog, for web requests and command-line scripts, framework-free:

```php
Log::info('Impersonation started');                    // one line, PHP's error_log()
Log::warn("[git] Move of app #12 refused: {$reason}");
Log::error("[mcp] create_app failed: {$e->getMessage()}");

$log = new Logger([new Stream_writer('[backup] ')]);      // STDOUT, e.g. a container's log
$log = new Logger([new Error_log_writer('[webhooks] ')]); // PHP's error_log()
$log->info('Dumped 12 tables');
$log->warn('...');
$log->error('...');
```

The `Log` facade writes to one shared `Logger`, which writes with `error_log()` until a script sets its own: `Log::use(new Logger([...]))`. Build a `Logger` yourself when a piece of code keeps its own log (a task, a backup run).

Each message goes to every writer as one entry (a multi-line message stays one entry, like Monolog's records); a blank one is skipped, and a writer that fails never stops the caller. Write a `Log_writer` to send messages elsewhere (the worker's `Task_log_writer` writes them to `task_logs`).

## Wiring

Nothing to register: the folder doesn't need the Trongate engine. Code
requires the one file it uses (a script outside Trongate passes its own path):

```php
require_once APPPATH . 'modules/log/Log.php';
```

`Log.php` requires `Logger.php` and the writers that ship with it.

## Tests

`tests/*.phpt`, run with php-src's `run-tests.php`:

```sh
php bin/run-tests.php -q modules/log
```
