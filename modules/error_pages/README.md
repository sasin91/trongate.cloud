# error_pages

Version 1.0.0

What a visitor sees when a page doesn't exist or a request fails, instead of
a bare 404 or 500. Calls made with `fetch()` (or with a JSON `Accept` or
`Content-Type`) get `{"error": "..."}` instead of a page. Failures are still
logged.

| Case | Status | Heading |
| --- | --- | --- |
| No such page | 404 | Page not found |
| Missing table or column (the schema wasn't applied after a deploy) | 503, `Retry-After: 60` | Updating |
| Request ran past `max_execution_time` | 503, `Retry-After: 60` | That took too long |
| Any other uncaught exception or fatal error | 500 | Something went wrong |

With `ENV` set to `dev`, a request from the machine the app runs on
(127.0.0.1 or ::1) also sees the exception and its stack trace. Nobody else
does, even when a public site is left on `dev`.

## Wiring

The folder is self-contained: it uses nothing from the app, only Trongate's
`Trongate` class and `public/css/trongate.css`. Two lines connect it.

`config/config.php`:

```php
define('ERROR_404', 'error_pages/not_found');
```

`engine/ignition.php`, after `spl_autoload_register(...)`:

```php
Error_pages::_register();
```

A fresh install whose `BASE_URL` is still `'****'` (and `ENV` is `dev`) keeps
getting Trongate's URL setup form from `templates/error_404`.

## Sharing between apps

The same folder sits unchanged in each Trongate app that uses it (the
recruiter and Trongate.cloud), the way Trongate's own modules do. Don't edit
it for one app: app differences go through config, not edits inside the
folder. Change it in one place, bump the version above, and copy the folder
to the others.

Once it has its own repository (e.g. `sasin91/trongate-error-pages`), each
app vendors it with
`git subtree add --prefix=modules/error_pages <repo> main --squash` and
sends fixes back with `git subtree push`. The folder can be split out as it
is with `git subtree split --prefix=modules/error_pages`. Composer isn't
used, since Trongate loads modules from `modules/` and Composer can't put
them there without a plugin.

## Tests

`tests/*.phpt`, run with php-src's `run-tests.php`:

```sh
php bin/run-tests.php -q modules/error_pages
```
