# cli

Building blocks for command-line scripts, framework-free (no Trongate engine needed):

- `Log` with handlers, after Monolog: `new Log([new Stream_handler('[backup] ')])`, then `info()`, `warn()`, `error()`. Write a `Log_handler` to send lines elsewhere (the worker's `Task_log_handler` writes them to `task_logs`).
- `Env::required('NAME')`: a setting from the environment, or a `RuntimeException` naming it.
- `Command::run(['tar', '-czf', ...])`: runs a program, its output passed through; throws on a non-zero exit.
- `Pipeline`: named lists of actions run in order; `exit($pipeline->run($argv[1] ?? ''))`.

Each file `require_once`s what it needs, so a script only requires the classes it uses.
