# cli

Version 1.0.0

Building blocks for command-line scripts, framework-free (no Trongate engine needed). They pair with `modules/log` (pass a `Process` line to `$log->write()`), but don't require it.

- `Env::required('NAME')`: a setting from the environment, or a `RuntimeException` naming it.
- `Process`, after Symfony's: `(new Process(['tar', '-czf', ...], cwd, env, timeout))->must_run()`, or `run($callback)` to get each line as it comes (`Process::OUT`/`Process::ERR`). Without a callback, `output()` and `error_output()` keep everything byte for byte, in memory: for big output (a PDF, video) let the program write a file itself (`tar -f`, `sh -c 'cmd > file'`) or pass a callback, which gets lines, or pieces of at most `MAX_LINE_BYTES` when there are no line breaks. A Process runs once; `run()` again throws `LogicException`, so make a new one per run.

Each file `require_once`s what it needs, so a script only requires the classes it uses.

## Wiring

Nothing to register: require the file you use (a script outside Trongate
passes its own path).

```php
require_once APPPATH . 'modules/cli/Env.php';
require_once APPPATH . 'modules/cli/Process.php';
```

## Tests

On Linux or macOS (they run `sh`, `sleep` and `true`):

```sh
php modules/cli/tests/Cli_test.php
```
