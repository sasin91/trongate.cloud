# cli

Version 1.1.0

Building blocks for command-line scripts, framework-free (no Trongate engine needed). They pair with `modules/log` (pass a `Process` line to `$log->write()`), but don't require it.

- `Env::required('NAME')`: a setting from the environment, or a `RuntimeException` naming it.
- `Process`, after Symfony's: `(new Process(['tar', '-czf', ...], cwd, env, timeout))->must_run()`, or `run($callback)` to get each line as it comes (`Process::OUT`/`Process::ERR`). Without a callback, `output()` and `error_output()` keep everything byte for byte, in memory: for big output (a PDF, video) let the program write a file itself (`tar -f`, `sh -c 'cmd > file'`) or pass a callback, which gets lines, or pieces of at most `MAX_LINE_BYTES` when there are no line breaks. Pass `input:` (a readable stream such as a file or `php://input`) to write it to the program's stdin while its output is read, and `stream($on_output)` to get stdout byte for byte in the pieces it arrives in (a CGI response, an archive) instead of as lines. A Process runs once; `run()` again throws `LogicException`, so make a new one per run.

Each file `require_once`s what it needs, so a script only requires the classes it uses.

## Wiring

Nothing to register: require the file you use (a script outside Trongate
passes its own path).

```php
require_once APPPATH . 'modules/cli/Env.php';
require_once APPPATH . 'modules/cli/Process.php';
```

## Tests

`tests/*.phpt`, run with php-src's `run-tests.php`:

```sh
php bin/run-tests.php -q modules/cli
```

The ones that run `sh`, `sleep` or `true` skip on Windows.
