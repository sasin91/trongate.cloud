# log

A log after Monolog, for web requests and command-line scripts, framework-free:

```php
$log = new Log([new Stream_handler('[backup] ')]);      // STDOUT, e.g. a container's log
$log = new Log([new Error_log_handler('[webhooks] ')]); // PHP's error_log()
$log->info('Dumped 12 tables');
$log->warn('...');
$log->error('...');
```

Each message goes to every handler one line at a time; a handler that fails never stops the caller. Write a `Log_handler` to send lines elsewhere (the worker's `Task_log_handler` writes them to `task_logs`).
