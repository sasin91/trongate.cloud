# cli

Building blocks for command-line scripts, framework-free (no Trongate engine needed). They log with `modules/log`.

- `Env::required('NAME')`: a setting from the environment, or a `RuntimeException` naming it.
- `Process`, after Symfony's: `(new Process(['tar', '-czf', ...], cwd, env, timeout))->must_run()`, or `run($callback)` to get each line as it comes (`Process::OUT`/`Process::ERR`). Without a callback, `output()` and `error_output()` keep everything byte for byte.
- `Pipeline`: named lists of actions run in order; `exit($pipeline->run($argv[1] ?? ''))`.

Each file `require_once`s what it needs, so a script only requires the classes it uses.
